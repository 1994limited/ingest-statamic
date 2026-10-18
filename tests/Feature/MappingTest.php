<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Facades\Ingest;
use NineteenNinetyFour\Ingest\Hooks\FeedHook;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Runs\RunItem;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

class MappingTest extends TestCase
{
    private function book(int $index = 0): \Statamic\Contracts\Entries\Entry
    {
        $isbn = $this->fixture('books/index.json')['data'][$index]['isbn'];

        return Entry::query()->where('collection', 'books')->where('isbn', $isbn)->first();
    }

    private function source(int $index = 0): array
    {
        return $this->fixture('books/index.json')['data'][$index];
    }

    private function feedWith(array $mapping, array $overrides = []): void
    {
        $this->booksFeed(['format' => ['item_path' => 'data'], 'mapping' => ['isbn' => ['path' => 'isbn']] + $mapping] + $overrides, 'books/index.json');
    }

    #[Test]
    public function hostile_html_is_sanitised_in_rich_text_and_stripped_from_plain_text(): void
    {
        $this->feedWith([
            'title' => ['template' => '{title} <script>alert(1)</script>'],
            'description' => ['path' => 'description_html'],
            'blurb' => ['path' => 'description_html'],
        ]);

        $this->ingest('books');

        // Books 7 and 13 carry <script>, onerror, javascript: and an iframe.
        foreach ([6, 12] as $i) {
            $entry = $this->book($i);
            $bard = json_encode($entry->get('description'));
            $this->assertStringNotContainsString('script', $bard);
            $this->assertStringNotContainsString('onerror', $bard);
            $this->assertStringNotContainsString('javascript:', $bard);
            $this->assertStringNotContainsString('iframe', $bard);
            $this->assertStringNotContainsString('<script', $entry->get('blurb'));
            $this->assertStringNotContainsString('javascript:', $entry->get('blurb'));
            $this->assertStringNotContainsString('<', $entry->get('title'));
        }

        $this->assertSame('paragraph', $this->book()->get('description')[0]['type']);
    }

    #[Test]
    public function transforms_change_values_in_order(): void
    {
        Ingest::transform('shout', fn ($value) => strtoupper((string) $value).'!');

        $this->feedWith([
            'title' => ['path' => 'title', 'transforms' => ['shout', ['name' => 'replace', 'args' => ['!', '?']]]],
            'price' => ['path' => 'formats.1.price', 'transforms' => [['name' => 'math', 'args' => ['* 1.2']], ['name' => 'round', 'args' => [2]]]],
            'subtitle' => ['path' => 'subtitle', 'transforms' => [['name' => 'default', 'args' => ['No subtitle']]]],
            'keywords' => ['path' => 'subjects', 'transforms' => ['lower']],
            'format' => ['path' => 'formats.0.binding', 'transforms' => [['name' => 'modifier', 'args' => ['lower']]]],
        ]);

        $run = $this->ingest('books');
        $this->assertSame(RunStatus::Completed, $run->status, (string) $run->message);

        $source = $this->source();
        $entry = $this->book();
        $this->assertSame(strtoupper($source['title']).'?', $entry->get('title'));
        $this->assertSame(round($source['formats'][1]['price'] * 1.2, 2), $entry->get('price'));
        $this->assertSame($source['subtitle'] ?? 'No subtitle', $entry->get('subtitle'));
        $this->assertSame(array_map('strtolower', $source['subjects']), $entry->get('keywords'));
        $this->assertSame('hardback', $entry->get('format'));
    }

    #[Test]
    public function empty_values_clear_keep_or_default(): void
    {
        $this->feedWith(['subtitle' => ['path' => 'subtitle']]);
        $this->ingest('books');
        $without = collect($this->fixture('books/index.json')['data'])->search(fn ($b) => $b['subtitle'] === null);
        $entry = $this->book($without);
        $entry->set('subtitle', 'Set on the site')->save();

        $this->feedWith(['subtitle' => ['path' => 'subtitle', 'empty' => 'keep']]);
        $this->ingest('books', options: ['force' => true]);
        $this->assertSame('Set on the site', Entry::find($entry->id())->get('subtitle'));

        $this->feedWith(['subtitle' => ['path' => 'subtitle', 'empty' => 'default', 'default' => 'None']]);
        $this->ingest('books', options: ['force' => true]);
        $this->assertSame('None', Entry::find($entry->id())->get('subtitle'));

        $this->feedWith(['subtitle' => ['path' => 'subtitle']]);
        $this->ingest('books', options: ['force' => true]);
        $this->assertNull(Entry::find($entry->id())->get('subtitle'));
    }

    #[Test]
    public function dates_toggles_selects_numbers_and_lists(): void
    {
        $this->booksFeed([
            'format' => ['item_path' => null],
            'mapping' => [
                'isbn' => ['path' => 'isbn'],
                'title' => ['path' => 'title'],
                'published_on' => ['path' => 'published'],
                'live' => ['path' => 'status', 'true_values' => ['live'], 'false_values' => ['withdrawn']],
                'pages' => ['path' => 'pages'],
                'price' => ['path' => 'paperback_price'],
                'keywords' => ['path' => 'subjects'],
                'format' => ['value' => 'Paperback'],
            ],
        ], 'books/index.csv');

        $run = $this->ingest('books');
        $this->assertSame(RunStatus::Completed, $run->status, (string) $run->message);

        $source = $this->source();
        $entry = $this->book();
        // The CSV has UK dates: 03/04/2026 is 3 April.
        $this->assertSame($source['published'], $entry->get('published_on'));
        $this->assertTrue($entry->get('live'));
        $this->assertSame($source['pages'], $entry->get('pages'));
        $this->assertSame((float) $source['formats'][1]['price'], $entry->get('price'));
        $this->assertSame($source['subjects'], $entry->get('keywords'));
        $this->assertSame('paperback', $entry->get('format'));

        $withdrawn = collect($this->fixture('books/index.json')['data'])->search(fn ($b) => $b['status'] === 'withdrawn');
        $this->assertFalse($this->book($withdrawn)->get('live'));
    }

    #[Test]
    public function unknown_options_are_left_out_with_a_warning(): void
    {
        $this->feedWith(['title' => ['path' => 'title'], 'format' => ['value' => 'Audiobook']]);

        $run = $this->ingest('books');

        $this->assertNull($this->book()->get('format'));
        $this->assertSame(60, $run->counts['warnings']);
        $this->assertStringContainsString('Audiobook', $this->items($run, RunItem::CREATED)[0]->warnings[0]);
    }

    #[Test]
    public function one_bad_field_saves_the_rest_or_fails_the_item(): void
    {
        $this->feedWith(['title' => ['path' => 'title'], 'pages' => ['value' => 'many']]);
        $run = $this->ingest('books');
        $this->assertSame(60, $run->counts['created']);
        $this->assertNotNull($this->book()->get('title'));
        $this->assertNull($this->book()->get('pages'));

        $this->feedWith(['title' => ['path' => 'title'], 'pages' => ['value' => 'many']], ['sync' => ['on_field_error' => 'skip_item']]);
        $run = $this->ingest('books', options: ['force' => true]);
        $this->assertSame(60, $run->counts['failed']);
    }

    #[Test]
    public function relationships_find_or_create_entries_and_terms(): void
    {
        Entry::make()->collection('authors')->slug('existing')->data(['title' => $this->source()['contributors'][0]['name']])->save();

        $this->feedWith([
            'title' => ['path' => 'title'],
            'authors' => ['path' => 'contributors.*.name', 'match' => 'title', 'create' => true],
            'subjects' => ['path' => 'subjects', 'match' => 'title', 'create' => true],
        ]);

        $this->ingest('books');

        $authors = $this->book()->get('authors');
        $this->assertSame(Entry::query()->where('collection', 'authors')->where('slug', 'existing')->first()->id(), $authors[0]);
        $this->assertSame(
            collect($this->fixture('books/index.json')['data'])->flatMap(fn ($b) => array_column($b['contributors'], 'name'))->unique()->count(),
            Entry::query()->where('collection', 'authors')->count(),
        );

        $subjects = $this->book()->get('subjects');
        $this->assertSame(array_map(fn ($s) => Str::slug($s), $this->source()['subjects']), $subjects);
        $this->assertNotNull(Term::find('subjects::'.$subjects[0]));
    }

    #[Test]
    public function a_dry_run_reports_relationships_it_would_create_without_creating_them(): void
    {
        $this->feedWith(['title' => ['path' => 'title'], 'authors' => ['path' => 'contributors.*.name', 'create' => true]]);

        $run = $this->ingest('books', true);

        $this->assertSame(0, Entry::query()->where('collection', 'authors')->count());
        $this->assertStringContainsString('would create', $this->items($run, RunItem::CREATED)[0]->warnings[0]);
    }

    #[Test]
    public function relationships_without_create_only_link_what_exists(): void
    {
        $this->feedWith(['title' => ['path' => 'title'], 'authors' => ['path' => 'contributors.*.name']]);

        $run = $this->ingest('books');

        $this->assertSame([], $this->book()->get('authors') ?? []);
        $this->assertSame(0, Entry::query()->where('collection', 'authors')->count());
        $this->assertGreaterThan(0, $run->counts['warnings']);
    }

    #[Test]
    public function grid_replicator_and_group_fields(): void
    {
        $this->feedWith([
            'title' => ['path' => 'title'],
            'formats' => ['path' => 'formats', 'merge_key' => 'sku', 'fields' => [
                'sku' => ['path' => 'sku'],
                'binding' => ['path' => 'binding'],
                'price' => ['path' => 'price'],
            ]],
            'blocks' => ['path' => 'contributors', 'set' => 'quote', 'fields' => [
                'quote' => ['template' => 'Written by {name}'],
                'cite' => ['path' => '^.publisher'],
            ]],
            'meta' => ['fields' => ['publisher' => ['path' => 'publisher'], 'status' => ['path' => 'status']]],
        ]);

        $this->ingest('books');

        $source = $this->source();
        $entry = $this->book();
        $this->assertCount(count($source['formats']), $entry->get('formats'));
        $this->assertSame($source['formats'][0]['sku'], $entry->get('formats')[0]['sku']);
        $this->assertArrayHasKey('id', $entry->get('formats')[0]);
        $this->assertSame('quote', $entry->get('blocks')[0]['type']);
        $this->assertTrue($entry->get('blocks')[0]['enabled']);
        $this->assertSame('Written by '.$source['contributors'][0]['name'], $entry->get('blocks')[0]['quote']);
        $this->assertSame($source['publisher'], $entry->get('blocks')[0]['cite']);
        $this->assertSame(['publisher' => $source['publisher'], 'status' => $source['status']], $entry->get('meta'));

        // Rows keep the same ids from run to run, so nothing looks changed.
        $this->assertSame(60, $this->ingest('books', options: ['force' => true])->counts['unchanged']);
    }

    #[Test]
    public function merged_rows_keep_fields_the_feed_doesnt_map(): void
    {
        $this->feedWith(['title' => ['path' => 'title'], 'formats' => ['path' => 'formats', 'mode' => 'merge', 'merge_key' => 'sku', 'fields' => ['sku' => ['path' => 'sku'], 'price' => ['path' => 'price']]]]);
        $this->ingest('books');

        $entry = $this->book();
        $rows = $entry->get('formats');
        $rows[0]['note'] = 'Signed copies';
        $rows[] = ['id' => 'mine', 'sku' => 'SPECIAL', 'note' => 'Shop exclusive'];
        $entry->set('formats', $rows)->save();

        $this->ingest('books', options: ['force' => true]);

        $after = Entry::find($entry->id())->get('formats');
        $this->assertSame('Signed copies', $after[0]['note']);
        $this->assertSame('SPECIAL', end($after)['sku']);
    }

    #[Test]
    public function images_are_downloaded_once_and_shared(): void
    {
        $this->feedWith([
            'title' => ['path' => 'title'],
            'cover' => ['template' => 'https://feeds.test/{cover}', 'folder' => 'books/{isbn}', 'alt' => 'title'],
        ]);

        $this->ingest('books');

        $entry = $this->book();
        $asset = Asset::find('assets::'.$entry->get('cover'));
        $this->assertNotNull($asset);
        $this->assertStringStartsWith('books/'.$entry->get('isbn').'/', $entry->get('cover'));
        $this->assertSame($entry->get('title'), $asset->get('alt'));
        $this->assertSame(60, count(glob(self::TMP.'/assets/books/*/*.png')));

        // Again: nothing downloaded.
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            return Http::response(file_get_contents(self::FEEDS.'/books/index.json'), 200, ['Content-Type' => 'application/json']);
        });
        $this->ingest('books', options: ['force' => true]);
        $this->assertSame(1, $requests);
    }

    #[Test]
    public function the_same_image_at_two_urls_is_one_asset(): void
    {
        $png = file_get_contents(self::FEEDS.'/images/covers/9791001000373.png');
        $this->serve('a/cover.png', fn () => Http::response($png, 200, ['Content-Type' => 'image/png']));
        $this->serve('b/cover.png', fn () => Http::response($png, 200, ['Content-Type' => 'image/png']));

        $this->feedWith(['title' => ['path' => 'title'], 'cover' => ['value' => 'https://feeds.test/a/cover.png']], ['sync' => ['limit' => 1]]);
        $this->ingest('books');
        $this->feedWith(['title' => ['path' => 'title'], 'cover' => ['value' => 'https://feeds.test/b/cover.png']], ['sync' => ['limit' => 1]]);
        $this->ingest('books', options: ['force' => true]);

        $this->assertCount(1, Asset::query()->where('container', 'assets')->get());
    }

    #[Test]
    public function only_allowed_file_types_are_kept(): void
    {
        $this->serve('evil.svg', fn () => Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 200, ['Content-Type' => 'image/png']));
        $this->serve('page.png', fn () => Http::response('<html><body>not an image</body></html>', 200, ['Content-Type' => 'image/png']));

        foreach (['evil.svg', 'page.png'] as $file) {
            $this->feedWith(['title' => ['path' => 'title'], 'cover' => ['value' => 'https://feeds.test/'.$file]], ['sync' => ['limit' => 1]]);
            $run = $this->ingest('books', options: ['force' => true]);

            $this->assertSame(0, Asset::query()->where('container', 'assets')->count(), $file);
            $this->assertNotEmpty($this->items($run)[0]->warnings);
        }
    }

    #[Test]
    public function hooks_can_change_or_skip_items(): void
    {
        Ingest::hook('supplier', SupplierHook::class);
        $this->feedWith(['title' => ['path' => 'title']], ['hook' => 'supplier']);

        $run = $this->ingest('books');

        $this->assertSame(1, $run->counts['skipped']);
        $this->assertStringStartsWith('[S] ', $this->book(1)->get('title'));
    }

    #[Test]
    public function a_missing_hook_fails_the_run_clearly(): void
    {
        $this->feedWith(['title' => ['path' => 'title']], ['hook' => 'nope']);

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Ingest::hook()', $run->message);
    }

    #[Test]
    public function prose_mirror_from_a_feed_is_sanitised_too(): void
    {
        $doc = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Click', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]];
        file_put_contents(self::TMP.'/pm.json', json_encode(['data' => [['isbn' => '1', 'title' => 'T', 'body' => $doc]]]));
        $this->serve('pm.json', self::TMP.'/pm.json');
        $this->booksFeed(['mapping' => ['title' => ['path' => 'title'], 'description' => ['path' => 'body']]], 'pm.json');

        $this->ingest('books');

        $saved = json_encode(Entry::query()->where('collection', 'books')->first()->get('description'));
        $this->assertStringContainsString('Click', $saved);
        $this->assertStringNotContainsString('javascript:', $saved);
    }
}

class SupplierHook extends FeedHook
{
    public function item(array $item, Context $context): ?array
    {
        return $item['isbn'] === '9791001000373' ? null : $item;
    }

    public function beforeSave(mixed $content, array $values, Context $context): array
    {
        $values['title'] = '[S] '.$values['title'];

        return $values;
    }
}
