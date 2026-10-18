<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Database\Tables;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Jobs\ProcessChunk;
use NineteenNinetyFour\Ingest\Jobs\StartRun;
use NineteenNinetyFour\Ingest\Notifications\RunNotification;
use NineteenNinetyFour\Ingest\Runs\Pruner;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\Runner;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use NineteenNinetyFour\Ingest\Webhooks\Token;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

class RunningTest extends TestCase
{
    #[Test]
    public function runs_are_queued_in_chunks_one_after_another(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']]);
        config(['ingest.chunk_size' => 7]);
        Queue::fake();

        $feed = $this->booksFeed();
        $run = app(Runner::class)->start($feed, 'manual');

        $this->assertSame(RunStatus::Queued, $run->status);
        Queue::assertPushed(StartRun::class, fn ($job) => $job->runId === $run->id);

        // The steps, run by hand as a worker would.
        (new StartRun($run->id))->handle(app(Runner::class));
        Queue::assertPushed(ProcessChunk::class, fn ($job) => $job->chunk === 0);

        for ($chunk = 0; app(Runner::class)->chunk($this->runs()->find($run->id), $chunk); $chunk++);
        app(Runner::class)->finish($this->runs()->find($run->id));

        $this->assertSame(2, $chunk); // 20 items in chunks of 7: 0, 1, 2
        $this->assertSame(20, $this->runs()->find($run->id)->counts['created']);
    }

    #[Test]
    public function a_run_can_be_cancelled_between_chunks(): void
    {
        config(['ingest.chunk_size' => 5]);
        $feed = $this->booksFeed();
        $runner = app(Runner::class);

        $run = Run::make('books');
        $this->runs()->save($run);
        $runner->fetch($run);
        $runner->chunk($run, 0);
        $runner->cancel($this->runs()->find($run->id));
        $this->assertFalse($runner->chunk($this->runs()->find($run->id), 1));

        $this->assertSame(RunStatus::Cancelled, $this->runs()->find($run->id)->status);
        $this->assertSame(5, Entry::query()->where('collection', 'books')->count());
    }

    #[Test]
    public function feeds_can_be_chained(): void
    {
        $this->booksFeed(['then_run' => ['second']]);
        $this->booksFeed([], 'states/books/v2.json', 'second');

        $this->ingest('books');

        $this->assertSame(1, $this->runs()->countForFeed('second'));
        $this->assertSame('chain', $this->runs()->forFeed('second')->first()->trigger);
    }

    #[Test]
    public function the_scheduler_starts_feeds_when_due(): void
    {
        $this->booksFeed(['schedule' => ['frequency' => 'hourly', 'minute' => 15]]);
        $this->booksFeed(['schedule' => ['frequency' => 'manual']], handle: 'manual');
        $this->booksFeed(['schedule' => ['frequency' => 'daily', 'time' => '06:30'], 'enabled' => false], handle: 'paused');

        $results = app(Scheduler::class)->tick(CarbonImmutable::parse('2026-10-18 10:15:00'));

        $this->assertSame(['books' => 'started'], $results);
        $this->assertSame([], app(Scheduler::class)->tick(CarbonImmutable::parse('2026-10-18 10:16:00')));
        $this->assertTrue(Scheduler::running());
    }

    #[Test]
    public function schedule_expressions(): void
    {
        $feed = fn (array $schedule) => Feed::make('x', ['schedule' => $schedule]);

        $this->assertSame('*/15 * * * *', Scheduler::expression($feed(['frequency' => 'minutes', 'every' => 15])));
        $this->assertSame('5 * * * *', Scheduler::expression($feed(['frequency' => 'hourly', 'minute' => 5])));
        $this->assertSame('30 6 * * *', Scheduler::expression($feed(['frequency' => 'daily', 'time' => '06:30'])));
        $this->assertSame('0 7 * * 1', Scheduler::expression($feed(['frequency' => 'weekly', 'day' => 1, 'time' => '07:00'])));
        $this->assertSame('0 */2 * * *', Scheduler::expression($feed(['frequency' => 'cron', 'expression' => '0 */2 * * *'])));
        $this->assertNull(Scheduler::expression($feed(['frequency' => 'cron', 'expression' => 'nonsense'])));
        $this->assertNull(Scheduler::expression($feed(['frequency' => 'manual'])));
    }

    #[Test]
    public function the_webhook_starts_a_feed_with_its_token(): void
    {
        $feed = $this->booksFeed();
        $token = Token::issue($feed);
        app(FeedRepository::class)->save($feed);

        $this->postJson('/!/ingest/feeds/books/run')->assertStatus(401);
        $this->postJson('/!/ingest/feeds/books/run', [], ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->postJson('/!/ingest/feeds/nope/run', [], ['Authorization' => 'Bearer '.$token])->assertStatus(401);

        $response = $this->postJson('/!/ingest/feeds/books/run', [], ['X-Ingest-Token' => $token]);

        $response->assertStatus(202)->assertJsonStructure(['run']);
        $this->assertSame('webhook', $this->runs()->find($response->json('run'))->trigger);
        $this->assertStringNotContainsString($token, file_get_contents(self::TMP.'/feeds/books.yaml'));
    }

    #[Test]
    public function the_webhook_says_when_a_feed_is_busy_or_paused(): void
    {
        $feed = $this->booksFeed();
        $token = Token::issue($feed);
        app(FeedRepository::class)->save($feed);

        $busy = Run::make('books');
        $busy->status = RunStatus::Processing;
        $busy->heartbeatAt = CarbonImmutable::now();
        $this->runs()->save($busy);
        $this->postJson('/!/ingest/feeds/books/run', [], ['X-Ingest-Token' => $token])->assertStatus(409)->assertJson(['run' => $busy->id]);

        $feed->set('enabled', false);
        app(FeedRepository::class)->save($feed);
        $this->postJson('/!/ingest/feeds/books/run', [], ['X-Ingest-Token' => $token])->assertStatus(423);
    }

    #[Test]
    public function failures_are_notified_once_then_the_recovery(): void
    {
        Notification::fake();
        config(['ingest.notifications.mail' => ['ops@example.com'], 'ingest.notifications.slack_webhook' => 'https://hooks.slack.test/x']);
        $this->serve('x', fn () => Http::response('ok'));
        $this->booksFeed(['notify' => ['on' => ['failed']]]);
        $this->point('books', 'missing.json');

        $this->ingest('books');
        $this->ingest('books');
        Notification::assertSentOnDemandTimes(RunNotification::class, 1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.test') && str_contains($request['text'], 'failed'));

        $this->point('books', 'states/books/v1.json');
        $this->ingest('books');
        Notification::assertSentOnDemandTimes(RunNotification::class, 2);
    }

    #[Test]
    public function dry_runs_never_notify(): void
    {
        Notification::fake();
        config(['ingest.notifications.mail' => ['ops@example.com']]);
        $this->booksFeed([], 'missing.json');

        $this->ingest('books', true);

        Notification::assertNothingSent();
    }

    #[Test]
    public function stale_feeds_are_notified(): void
    {
        Notification::fake();
        config(['ingest.notifications.mail' => ['ops@example.com']]);
        $this->booksFeed(['schedule' => ['frequency' => 'daily', 'time' => '03:00'], 'notify' => ['stale_after_hours' => 24]]);
        $this->ingest('books');

        $this->travel(2)->days();
        app(Scheduler::class)->tick();
        app(Scheduler::class)->tick();

        Notification::assertSentOnDemandTimes(RunNotification::class, 1);
    }

    #[Test]
    public function run_history_is_pruned(): void
    {
        config(['ingest.history.keep_runs' => 2]);
        $this->booksFeed();
        foreach (range(1, 4) as $i) {
            $this->point('books', $i % 2 ? 'states/books/v1.json' : 'states/books/v2.json');
            $this->ingest('books');
        }

        app(Pruner::class)->prune();

        $runs = $this->runs()->forFeed('books');
        $this->assertCount(4, $runs);
        $this->assertSame(0, $this->runs()->items($runs[3])['total']);
        $this->assertGreaterThan(0, $this->runs()->items($runs[0])['total']);
    }

    #[Test]
    public function data_moves_between_drivers(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->booksFeed();
        $this->ingest('books');

        $this->artisan('ingest:transfer', ['--to' => 'database'])->assertSuccessful();

        $this->assertSame(1, Tables::query('feeds')->count());
        $this->assertSame(1, Tables::query('runs')->count());
        $this->assertSame(20, Tables::query('items')->count());
        $this->assertSame(20, Tables::query('run_items')->count());
    }

    #[Test]
    public function transferring_without_tables_explains_the_steps(): void
    {
        $this->artisan('ingest:transfer', ['--to' => 'database'])
            ->expectsOutputToContain('vendor:publish --tag=ingest-migrations')
            ->assertFailed();
    }

    #[Test]
    public function the_commands_run_check_and_undo_feeds(): void
    {
        $this->booksFeed();

        $this->artisan('ingest:run', ['feed' => ['books'], '--dry' => true])->expectsOutputToContain('20 created')->assertSuccessful();
        $this->assertSame(0, Entry::query()->where('collection', 'books')->count());

        $this->artisan('ingest:run', ['feed' => ['books']])->assertSuccessful();
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());

        $this->artisan('ingest:check', ['feed' => 'books'])->expectsOutputToContain('json')->assertSuccessful();
        $this->artisan('ingest:list')->expectsOutputToContain('books')->assertSuccessful();

        $run = $this->runs()->forFeed('books')->first();
        $this->artisan('ingest:undo', ['run' => $run->id, '--force' => true])->assertSuccessful();
        $this->assertSame(0, Entry::query()->where('collection', 'books')->count());

        $this->artisan('ingest:run', ['feed' => ['nope']])->assertFailed();
    }
}
