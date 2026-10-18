<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use NineteenNinetyFour\Ingest\Webhooks\Token;
use NineteenNinetyFour\Ingest\Widgets\FeedHealth;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;

class ControlPanelTest extends TestCase
{
    use FakesRoles;

    private function editor(array $permissions = ['view ingest', 'run ingest feeds', 'manage ingest feeds', 'undo ingest runs']): \Statamic\Contracts\Auth\User
    {
        $this->setTestRoles(['ingester' => ['access cp', ...$permissions]]);
        $user = User::make()->email('editor@example.com')->assignRole('ingester');
        $user->save();

        return $user;
    }

    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Books',
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/states/books/v1.json', 'headers' => [['name' => 'Accept', 'value' => 'application/json']]],
            'format' => ['type' => 'auto', 'item_path' => 'data'],
            'destination' => ['type' => 'entries', 'collection' => 'books', 'blueprint' => 'book'],
            'key' => ['isbn'],
            'mapping' => ['title' => ['path' => 'title'], 'isbn' => ['path' => 'isbn'], 'pages' => ['path' => '']],
        ], $overrides);
    }

    #[Test]
    public function the_feeds_page_lists_feeds(): void
    {
        $this->booksFeed();
        $this->ingest('books');

        $this->actingAs($this->editor())
            ->get(cp_route('ingest.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ingest::Index')
                ->has('feeds', 1)
                ->where('feeds.0.handle', 'books')
                ->where('feeds.0.last_run.counts.created', 20));
    }

    #[Test]
    public function people_without_permission_cant_see_or_change_feeds(): void
    {
        $this->booksFeed();
        $viewer = $this->editor(['view ingest']);

        $this->actingAs($viewer)->get(cp_route('ingest.index'))->assertOk();
        $this->actingAs($viewer)->getJson(cp_route('ingest.feeds.edit', 'books'))->assertForbidden();
        $this->actingAs($viewer)->postJson(cp_route('ingest.feeds.run', 'books'))->assertForbidden();
        $this->actingAs($viewer)->postJson(cp_route('ingest.preview'), ['feed' => $this->form()])->assertForbidden();

        $nobody = User::make()->email('nobody@example.com');
        $nobody->save();
        $this->actingAs($nobody)->get(cp_route('ingest.index'))->assertRedirect();
    }

    #[Test]
    public function a_feed_is_created_from_the_form(): void
    {
        $this->actingAs($this->editor())
            ->postJson(cp_route('ingest.feeds.store'), ['feed' => $this->form()])
            ->assertOk()
            ->assertJson(['redirect' => cp_route('ingest.feeds.show', 'books')]);

        $feed = app(FeedRepository::class)->find('books');
        $this->assertSame(['Accept' => 'application/json'], $feed->source()['headers']);
        // An empty mapping isn't kept.
        $this->assertArrayNotHasKey('pages', $feed->mapping());
        $this->assertFileExists(self::TMP.'/feeds/books.yaml');
    }

    #[Test]
    public function the_form_is_validated(): void
    {
        $this->actingAs($this->editor())
            ->postJson(cp_route('ingest.feeds.store'), ['feed' => ['key' => []] + $this->form([
                'source' => ['url' => 'not a url', 'auth' => ['type' => 'bearer', 'token' => 'plain-secret']],
                'mapping' => ['nope' => ['path' => 'x']],
                'schedule' => ['frequency' => 'cron', 'expression' => 'every tuesday'],
            ])])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source.url', 'source.auth.token', 'key', 'mapping.nope', 'schedule.expression']);
    }

    #[Test]
    public function a_feed_is_updated_without_touching_its_webhook(): void
    {
        $feed = $this->booksFeed();
        Token::issue($feed);
        app(FeedRepository::class)->save($feed);
        $hash = $feed->get('webhook.token_hash');

        $this->actingAs($this->editor())
            ->putJson(cp_route('ingest.feeds.update', 'books'), ['feed' => $this->form(['title' => 'Renamed', 'webhook' => ['token_hash' => 'forged']])])
            ->assertOk();

        $saved = app(FeedRepository::class)->find('books');
        $this->assertSame('Renamed', $saved->title());
        $this->assertSame($hash, $saved->get('webhook.token_hash'));
    }

    #[Test]
    public function the_edit_page_never_sends_the_webhook_hash(): void
    {
        $feed = $this->booksFeed();
        Token::issue($feed);
        app(FeedRepository::class)->save($feed);

        $this->actingAs($this->editor())
            ->get(cp_route('ingest.feeds.edit', 'books'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ingest::Feed')->missing('feed.webhook'));
    }

    #[Test]
    public function the_preview_reads_the_feed_and_maps_it(): void
    {
        $response = $this->actingAs($this->editor())
            ->postJson(cp_route('ingest.preview'), ['handle' => 'books', 'feed' => $this->form()])
            ->assertOk();

        $this->assertSame('json', $response->json('format'));
        $this->assertSame(20, $response->json('count'));
        $this->assertArrayHasKey('formats.*.sku', $response->json('paths'));
        $this->assertSame($this->fixture('states/books/v1.json')['data'][0]['title'], $response->json('mapped.0.values.title'));
        $this->assertContains('data', array_column($response->json('tree'), 'path'));
    }

    #[Test]
    public function the_preview_explains_a_broken_feed(): void
    {
        $this->actingAs($this->editor())
            ->postJson(cp_route('ingest.preview'), ['feed' => $this->form(['source' => ['url' => 'https://feeds.test/states/books/not-a-feed.html']])])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The feed URL returned a web page, not a feed. Check the URL, and whether it needs signing in.']);
    }

    #[Test]
    public function the_fields_endpoint_describes_the_blueprint(): void
    {
        $fields = collect($this->actingAs($this->editor())
            ->postJson(cp_route('ingest.meta.fields'), ['destination' => ['type' => 'entries', 'collection' => 'books', 'blueprint' => 'book']])
            ->assertOk()
            ->json('fields'))->keyBy('handle');

        $this->assertSame('rows', $fields['formats']['kind']);
        $this->assertSame(['sku', 'binding', 'price', 'note'], array_column($fields['formats']['fields'], 'handle'));
        $this->assertSame(['text', 'quote'], array_column($fields['blocks']['sets'], 'handle'));
        $this->assertSame('options', $fields['format']['kind']);
        $this->assertSame('relationship', $fields['authors']['kind']);
        $this->assertTrue($fields->has('slug'));
        $this->assertTrue($fields->has('published'));
    }

    #[Test]
    public function the_options_endpoint_lists_what_a_feed_can_use(): void
    {
        putenv('SET_ONE=x');
        $options = $this->actingAs($this->editor())->getJson(cp_route('ingest.meta.options', ['env' => 'SET_ONE,NOT_SET']))->assertOk()->json();

        $this->assertContains('books', array_column($options['collections'], 'value'));
        $this->assertContains('json', array_column($options['formats'], 'value'));
        $this->assertSame([['name' => 'SET_ONE', 'set' => true], ['name' => 'NOT_SET', 'set' => false]], $options['env']);
        putenv('SET_ONE');
    }

    #[Test]
    public function runs_are_started_watched_and_undone(): void
    {
        $this->booksFeed();
        $editor = $this->editor();

        $start = $this->actingAs($editor)->postJson(cp_route('ingest.feeds.run', 'books'), ['dry' => true])->assertOk();
        $dryId = $start->json('run.id');
        $this->assertTrue($start->json('run.dry_run'));
        $this->assertSame(0, Entry::query()->where('collection', 'books')->count());

        $this->actingAs($editor)->get(cp_route('ingest.runs.show', ['books', $dryId]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ingest::Run')->where('run.counts.created', 20));

        $items = $this->actingAs($editor)->getJson(cp_route('ingest.runs.items', [$dryId, 'action' => 'created']))->assertOk();
        $this->assertSame(20, $items->json('total'));
        $this->assertSame('title', $items->json('items.0.changes.0.field'));

        $applied = $this->actingAs($editor)->postJson(cp_route('ingest.feeds.run', 'books'), ['apply' => $dryId])->assertOk();
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());

        $runId = $applied->json('run.id');
        $this->actingAs($editor)->getJson(cp_route('ingest.runs.status', $runId))->assertOk()->assertJsonPath('run.status', 'completed');
        $this->actingAs($editor)->postJson(cp_route('ingest.runs.undo', $runId))->assertOk();
        $this->assertSame(0, Entry::query()->where('collection', 'books')->count());
        $this->actingAs($editor)->postJson(cp_route('ingest.runs.undo', $runId))->assertStatus(422);
    }

    #[Test]
    public function a_busy_feed_says_so(): void
    {
        $this->booksFeed();
        $busy = Run::make('books');
        $busy->status = RunStatus::Processing;
        $busy->heartbeatAt = now()->toImmutable();
        $this->runs()->save($busy);

        $this->actingAs($this->editor())->postJson(cp_route('ingest.feeds.run', 'books'))->assertStatus(409)->assertJsonPath('run.id', $busy->id);
    }

    #[Test]
    public function feeds_are_duplicated_paused_and_without_the_webhook(): void
    {
        $feed = $this->booksFeed();
        Token::issue($feed);
        app(FeedRepository::class)->save($feed);

        $this->actingAs($this->editor())->postJson(cp_route('ingest.feeds.duplicate', 'books'))->assertOk();

        $copy = app(FeedRepository::class)->find('books_copy');
        $this->assertFalse($copy->enabled());
        $this->assertNull($copy->get('webhook.token_hash'));
        $this->assertSame($feed->mapping(), $copy->mapping());
    }

    #[Test]
    public function webhook_tokens_are_shown_once(): void
    {
        $this->booksFeed();

        $token = $this->actingAs($this->editor())->postJson(cp_route('ingest.feeds.webhook', 'books'))->assertOk()->json('token');

        $this->assertStringStartsWith('ing_', $token);
        $this->postJson('/!/ingest/feeds/books/run', [], ['Authorization' => 'Bearer '.$token])->assertStatus(202);

        $this->actingAs($this->editor())->deleteJson(cp_route('ingest.feeds.webhook', 'books'))->assertOk();
        $this->postJson('/!/ingest/feeds/books/run', [], ['Authorization' => 'Bearer '.$token])->assertStatus(401);
    }

    #[Test]
    public function files_are_uploaded_privately(): void
    {
        $response = $this->actingAs($this->editor())->postJson(cp_route('ingest.feeds.upload'), [
            'handle' => 'stock',
            'file' => UploadedFile::fake()->createWithContent('Stock List.csv', "sku,stock\nA,1\n"),
        ])->assertOk();

        $this->assertSame('stock-list.csv', $response->json('file'));
        $this->assertFileExists(self::TMP.'/ingest/uploads/stock/stock-list.csv');

        $this->actingAs($this->editor())->postJson(cp_route('ingest.feeds.upload'), [
            'handle' => 'stock',
            'file' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'),
        ])->assertStatus(422);
    }

    #[Test]
    public function deleting_a_feed_keeps_its_content(): void
    {
        $this->booksFeed();
        $this->ingest('books');

        $this->actingAs($this->editor())->deleteJson(cp_route('ingest.feeds.destroy', 'books'))->assertOk();

        $this->assertNull(app(FeedRepository::class)->find('books'));
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());
        $this->assertSame(0, $this->map()->count('books'));
    }

    #[Test]
    public function the_dashboard_widget_shows_feeds(): void
    {
        $this->booksFeed();
        $this->actingAs($this->editor());

        $component = (new FeedHealth)->component();

        $data = $component->toArray();
        $this->assertSame('ingest-feed-health', $data['name']);
        $this->assertCount(1, $data['props']['feeds']);
    }
}
