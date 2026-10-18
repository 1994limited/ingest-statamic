<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Runs\RunItem;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

class SyncTest extends TestCase
{
    #[Test]
    public function it_creates_an_entry_for_each_item(): void
    {
        $this->booksFeed();

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Completed, $run->status, (string) $run->message);
        $this->assertSame(20, $run->total);
        $this->assertSame(20, $run->counts['created']);

        $first = $this->fixture('states/books/v1.json')['data'][0];
        $entry = Entry::query()->where('collection', 'books')->where('isbn', $first['isbn'])->first();

        $this->assertNotNull($entry);
        $this->assertSame($first['title'], $entry->get('title'));
        $this->assertSame($first['pages'], $entry->get('pages'));
        $this->assertSame((float) $first['formats'][1]['price'], $entry->get('price'));
        $this->assertTrue($entry->published());
        $this->assertSame(Str::slug($first['title']), $entry->slug());
        $this->assertSame(20, $this->map()->count('books'));
    }

    #[Test]
    public function a_second_run_of_the_same_feed_changes_nothing(): void
    {
        $this->booksFeed();
        $this->ingest('books');

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(0, $run->counts['created']);
        $this->assertSame(0, $run->counts['updated']);
        $this->assertSame(20, $run->counts['unchanged']);
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());
    }

    #[Test]
    public function changes_in_the_feed_update_only_what_changed(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');

        $run = $this->ingest('books');
        $manifest = $this->fixture('states/books/manifest.json')['v2'];

        // 5 prices and 1 title changed, 1 book added.
        $this->assertSame(6, $run->counts['updated']);
        $this->assertSame(1, $run->counts['created']);
        $this->assertSame(13, $run->counts['unchanged']);

        $updated = $this->items($run, RunItem::UPDATED);
        $titleChange = collect($updated)->first(fn ($i) => isset($i->changes['title']));
        $this->assertStringEndsWith('(Revised Edition)', $titleChange->changes['title'][1]);
        $this->assertNotSame($titleChange->changes['title'][0], $titleChange->changes['title'][1]);
        // Only the fields that changed are listed.
        $this->assertSame(['title'], array_keys($titleChange->changes));

        $this->assertNotNull(Entry::query()->where('isbn', $manifest['added'][0])->first());
    }

    #[Test]
    public function items_missing_from_the_feed_are_left_alone_by_default(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');

        $run = $this->ingest('books');
        $removed = $this->fixture('states/books/manifest.json')['v2']['removed'][0];

        $this->assertSame(0, $run->counts['unpublished'] + $run->counts['deleted']);
        $this->assertTrue(Entry::query()->where('isbn', $removed)->first()->published());
    }

    #[Test]
    public function items_missing_from_the_feed_can_be_unpublished_and_come_back(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'unpublish']]]);
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $removed = $this->fixture('states/books/manifest.json')['v2']['removed'][0];

        $run = $this->ingest('books');

        $this->assertSame(1, $run->counts['unpublished']);
        $this->assertFalse(Entry::query()->where('isbn', $removed)->first()->published());

        // Running again doesn't unpublish it again.
        $this->assertSame(0, $this->ingest('books')->counts['unpublished']);

        // Back in the feed: published again.
        $this->point('books', 'states/books/v1.json');
        $back = $this->ingest('books');
        $this->assertTrue(Entry::query()->where('isbn', $removed)->first()->published());
        $this->assertGreaterThanOrEqual(1, $back->counts['updated']);
    }

    #[Test]
    public function items_missing_from_the_feed_can_be_deleted(): void
    {
        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete']]]);
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $removed = $this->fixture('states/books/manifest.json')['v2']['removed'][0];

        $run = $this->ingest('books');

        $this->assertSame(1, $run->counts['deleted']);
        $this->assertNull(Entry::query()->where('isbn', $removed)->first());
        $deleted = $this->items($run, RunItem::DELETED)[0];
        $this->assertSame($removed, $deleted->snapshot['content']['data']['isbn']);
    }

    #[Test]
    public function only_items_the_feed_owns_are_ever_removed(): void
    {
        // Content made by hand in the same collection.
        Entry::make()->collection('books')->slug('hand-made')->data(['title' => 'Hand made', 'isbn' => 'none'])->save();

        $this->booksFeed(['sync' => ['missing' => ['action' => 'delete']]]);
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $this->ingest('books');

        $this->assertNotNull(Entry::query()->where('slug', 'hand-made')->first());
    }

    #[Test]
    public function only_mapped_fields_are_touched(): void
    {
        $this->booksFeed();
        $this->ingest('books');

        $entry = Entry::query()->where('collection', 'books')->first();
        $entry->set('blurb', 'Written by our staff')->save();

        $this->point('books', 'states/books/v2.json');
        $this->ingest('books', options: ['force' => true]);

        $this->assertSame('Written by our staff', Entry::find($entry->id())->get('blurb'));
    }

    #[Test]
    public function new_items_can_be_saved_as_drafts(): void
    {
        $this->booksFeed(['sync' => ['publish_new' => false]]);

        $this->ingest('books');

        $this->assertFalse(Entry::query()->where('collection', 'books')->first()->published());
    }

    #[Test]
    public function creating_and_updating_can_be_switched_off(): void
    {
        $this->booksFeed(['sync' => ['create' => false]]);
        $run = $this->ingest('books');
        $this->assertSame(0, Entry::query()->where('collection', 'books')->count());
        $this->assertSame(20, $run->counts['skipped']);

        $this->booksFeed(['sync' => ['create' => true, 'update' => false]]);
        $this->ingest('books');
        $this->point('books', 'states/books/v2.json');
        $run = $this->ingest('books');
        $this->assertSame(0, $run->counts['updated']);
    }

    #[Test]
    public function items_with_the_same_key_twice_keep_the_first(): void
    {
        $data = $this->fixture('states/books/v1.json');
        $duplicate = $data['data'][0];
        $duplicate['title'] = 'Duplicate';
        $data['data'][] = $duplicate;
        file_put_contents(self::TMP.'/dup.json', json_encode($data));
        $this->serve('dup.json', self::TMP.'/dup.json');

        $this->booksFeed(path: 'dup.json');
        $run = $this->ingest('books');

        $this->assertSame(20, $run->counts['created']);
        $this->assertSame(1, $run->counts['skipped']);
        $this->assertNull(Entry::query()->where('title', 'Duplicate')->first());
    }

    #[Test]
    public function items_without_a_key_fail_and_the_rest_carry_on(): void
    {
        $data = $this->fixture('states/books/v1.json');
        unset($data['data'][3]['isbn']);
        file_put_contents(self::TMP.'/nokey.json', json_encode($data));
        $this->serve('nokey.json', self::TMP.'/nokey.json');

        $this->booksFeed(path: 'nokey.json');
        $run = $this->ingest('books');

        $this->assertSame(RunStatus::CompletedWithErrors, $run->status);
        $this->assertSame(1, $run->counts['failed']);
        $this->assertSame(19, $run->counts['created']);
    }

    #[Test]
    public function an_entry_deleted_on_the_site_is_created_again(): void
    {
        $this->booksFeed();
        $this->ingest('books');
        Entry::query()->where('collection', 'books')->first()->delete();

        $run = $this->ingest('books', options: ['force' => true]);

        $this->assertSame(1, $run->counts['created']);
        $this->assertSame(20, Entry::query()->where('collection', 'books')->count());
    }

    #[Test]
    public function filters_leave_items_out(): void
    {
        $this->booksFeed(['filters' => [['path' => 'pages', 'op' => 'gt', 'value' => 300]]]);

        $run = $this->ingest('books');

        $expected = collect($this->fixture('states/books/v1.json')['data'])->filter(fn ($b) => $b['pages'] > 300)->count();
        $this->assertSame($expected, $run->counts['created']);
        $this->assertSame(20 - $expected, $run->counts['skipped']);
    }

    #[Test]
    public function existing_content_can_be_adopted_by_a_field(): void
    {
        $first = $this->fixture('states/books/v1.json')['data'][0];
        $existing = Entry::make()->collection('books')->slug('mine')->data(['title' => 'Old title', 'isbn' => $first['isbn']]);
        $existing->save();

        $this->booksFeed(['sync' => ['adopt' => ['field' => 'isbn', 'path' => 'isbn']]]);
        $run = $this->ingest('books');

        $this->assertSame(19, $run->counts['created']);
        $this->assertSame(1, $run->counts['updated']);
        $this->assertSame($first['title'], Entry::find($existing->id())->get('title'));
        $this->assertSame($existing->id(), $this->map()->forContent($existing->id())?->contentId);
    }

    #[Test]
    public function local_edits_can_be_kept(): void
    {
        $this->booksFeed(['sync' => ['local_edits' => 'keep']]);
        $this->ingest('books');

        $v2 = collect($this->fixture('states/books/v2.json')['data']);
        $changed = $v2->first(fn ($b) => str_ends_with($b['title'], '(Revised Edition)'));
        $entry = Entry::query()->where('isbn', $changed['isbn'])->first();
        $entry->set('title', 'Our own title')->save();

        $this->point('books', 'states/books/v2.json');
        $run = $this->ingest('books');

        $this->assertSame('Our own title', Entry::find($entry->id())->get('title'));
        $this->assertSame(1, $run->counts['conflict']);
    }

    #[Test]
    public function local_edits_are_overwritten_by_default(): void
    {
        $this->booksFeed();
        $this->ingest('books');

        $v2 = collect($this->fixture('states/books/v2.json')['data']);
        $changed = $v2->first(fn ($b) => str_ends_with($b['title'], '(Revised Edition)'));
        $entry = Entry::query()->where('isbn', $changed['isbn'])->first();
        $entry->set('title', 'Our own title')->save();

        $this->point('books', 'states/books/v2.json');
        $this->ingest('books');

        $this->assertSame($changed['title'], Entry::find($entry->id())->get('title'));
    }
}
