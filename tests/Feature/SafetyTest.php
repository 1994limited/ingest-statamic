<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Support\Facades\Http;
use NineteenNinetyFour\Ingest\Runs\AlreadyRunning;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunItem;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Runs\Undoer;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

class SafetyTest extends TestCase
{
    private function published(): int
    {
        return Entry::query()->where('collection', 'books')->where('published', true)->count();
    }

    #[Test]
    public function an_empty_feed_never_removes_anything(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete']]]);
        $this->ingest('books');
        $this->point('books', 'states/books/empty.json');

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Aborted, $run->status);
        $this->assertStringContainsString('no items', $run->message);
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());
    }

    #[Test]
    public function an_empty_feed_can_be_allowed(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'unpublish'], 'safety' => ['allow_empty' => true, 'max_removal_percent' => 100]]]);
        $this->ingest('books');
        $this->point('books', 'states/books/empty.json');

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(0, $this->published());
    }

    #[Test]
    public function removing_more_than_the_threshold_is_stopped(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete'], 'safety' => ['max_removal_percent' => 25]]]);
        $this->ingest('books');

        // Only 10 of the 20 books.
        $data = $this->fixture('states/books/v1.json');
        $data['data'] = array_slice($data['data'], 0, 10);
        file_put_contents(self::TMP.'/half.json', json_encode($data));
        $this->serve('half.json', self::TMP.'/half.json');
        $this->point('books', 'half.json');

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Aborted, $run->status);
        $this->assertStringContainsString('10 of 20', $run->message);
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());

        // Forced: it goes ahead.
        $forced = $this->ingest('books', options: ['force' => true]);
        $this->assertSame(10, $forced->counts['deleted']);
    }

    #[Test]
    public function a_broken_feed_fails_without_touching_anything(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete']]]);
        $this->ingest('books');

        foreach (['states/books/truncated.json', 'states/books/not-a-feed.html'] as $broken) {
            $this->point('books', $broken);
            $run = $this->ingest('books');

            $this->assertSame(RunStatus::Failed, $run->status, $broken);
            $this->assertSame(20, Entry::query()->where('collection', 'books')->count());
        }

        $this->serve('error.json', fn () => Http::response('Server error', 500));
        $this->point('books', 'error.json');
        $run = $this->ingest('books');
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('500', $run->message);
    }

    #[Test]
    public function a_run_with_an_item_limit_never_removes_anything(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete'], 'safety' => ['max_removal_percent' => 100]]]);
        $this->ingest('books');

        $run = $this->ingest('books', options: ['limit' => 2]);

        $this->assertSame(2, $run->total);
        $this->assertSame(0, $run->counts['deleted']);
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());
    }

    #[Test]
    public function a_dry_run_changes_nothing_but_shows_what_would(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'unpublish']]]);
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');

        $dry = $this->ingest('books', true);

        $this->assertTrue($dry->dryRun);
        $this->assertSame(6, $dry->counts['updated']);
        $this->assertSame(1, $dry->counts['created']);
        $this->assertSame(1, $dry->counts['unpublished']);
        $this->assertSame(20, $this->published());
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());

        $created = $this->items($dry, RunItem::CREATED)[0];
        $this->assertNull($created->contentId);
        $this->assertSame([null, $created->changes['title'][1]], $created->changes['title']);
    }

    #[Test]
    public function a_dry_run_can_be_applied_exactly_as_previewed(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $dry = $this->ingest('books', true);

        // The feed changes again after the preview: applying uses what was previewed.
        $this->point('books', 'states/books/v3.json');
        $applied = $this->ingest('books', options: ['apply' => $dry->id]);

        $this->assertSame('apply', $applied->payload['applied_from'] ? 'apply' : null);
        $this->assertSame($dry->counts['updated'], $applied->counts['updated']);
        $this->assertSame($dry->counts['created'], $applied->counts['created']);
    }

    #[Test]
    public function a_run_can_be_undone(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete']]]);
        $this->ingest('books');
        $before = Entry::query()->where('collection', 'books')->get()->mapWithKeys(fn ($e) => [$e->get('isbn') => $e->get('title')])->all();
        $this->point('books', 'states/books/v2.json');
        $run = $this->ingest('books');
        $manifest = $this->fixture('states/books/manifest.json')['v2'];

        $result = app(Undoer::class)->undo($run);

        $this->assertSame([], $result['conflicts']);
        $after = Entry::query()->where('collection', 'books')->get()->mapWithKeys(fn ($e) => [$e->get('isbn') => $e->get('title')])->all();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
        $this->assertNull(Entry::query()->where('isbn', $manifest['added'][0])->first());
        $this->assertSame(RunStatus::Undone, $this->runs()->find($run->id)->status);

        // The next run brings the changes back.
        $again = $this->ingest('books');
        $this->assertSame(6, $again->counts['updated']);
        $this->assertSame(1, $again->counts['created']);
        $this->assertSame(1, $again->counts['deleted']);
    }

    #[Test]
    public function undo_keeps_what_was_edited_since(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $run = $this->ingest('books');

        $updated = collect($this->items($run, RunItem::UPDATED))->first(fn ($i) => isset($i->changes['title']));
        Entry::find($updated->contentId)->set('title', 'Edited after')->save();

        $result = app(Undoer::class)->undo($run);

        $this->assertCount(1, $result['conflicts']);
        $this->assertSame('Edited after', Entry::find($updated->contentId)->get('title'));
    }

    #[Test]
    public function runs_are_undone_newest_first(): void
    {
        $this->booksFeed();
        $first = $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $this->ingest('books');

        $this->assertNotNull(app(Undoer::class)->canUndo($first));
    }

    #[Test]
    public function a_feed_cant_run_twice_at_once(): void
    {
        $this->booksFeed();
        $run = Run::make('books');
        $run->status = RunStatus::Processing;
        $run->heartbeatAt = now()->toImmutable();
        $this->runs()->save($run);

        $this->expectException(AlreadyRunning::class);
        $this->ingest('books');
    }

    #[Test]
    public function a_stalled_run_is_given_up_on(): void
    {
        $this->booksFeed();
        $run = Run::make('books');
        $run->status = RunStatus::Processing;
        $run->heartbeatAt = now()->subHour()->toImmutable();
        $this->runs()->save($run);

        $this->assertSame(RunStatus::Completed, $this->ingest('books')->status);
        $this->assertSame(RunStatus::Failed, $this->runs()->find($run->id)->status);
    }

    #[Test]
    public function an_unchanged_feed_is_noticed_without_reading_it(): void
    {
        $this->booksFeed();
        $this->serve('states/books/v1.json', fn ($request) => $request->hasHeader('If-None-Match')
            ? Http::response('', 304)
            : Http::response(file_get_contents(self::FEEDS.'/states/books/v1.json'), 200, ['ETag' => '"v1"', 'Content-Type' => 'application/json']));

        $this->ingest('books');
        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertTrue($run->payload['unchanged']);
        $this->assertSame(0, $run->counts['unchanged']);
    }
}
