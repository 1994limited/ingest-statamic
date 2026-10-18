<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use NineteenNinetyFour\Ingest\Sources\FileSource;
use NineteenNinetyFour\Ingest\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

class FormatsAndSourcesTest extends TestCase
{
    public static function formats(): array
    {
        return [
            'json' => ['books/index.json', ['item_path' => 'data'], 'isbn', 'title'],
            'ndjson' => ['books/index.ndjson', [], 'isbn', 'title'],
            'xml' => ['books/index.xml', ['item_path' => 'catalogue/book'], '@id', 'title'],
            'csv' => ['books/index.csv', [], 'isbn', 'title'],
            'onix' => ['books/onix.xml', ['item_path' => 'ONIXMessage/Product'], 'RecordReference', 'DescriptiveDetail.TitleDetail.TitleElement.TitleText'],
        ];
    }

    #[Test]
    #[DataProvider('formats')]
    public function every_format_imports(string $path, array $format, string $key, string $title): void
    {
        $this->booksFeed([
            'format' => ['type' => 'auto'] + $format,
            'key' => [$key],
            'mapping' => ['title' => ['path' => $title], 'isbn' => ['path' => $key]],
        ], $path);

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Completed, $run->status, (string) $run->message);
        $this->assertGreaterThanOrEqual(60, $run->counts['created']);
        $this->assertNotEmpty(Entry::query()->where('collection', 'books')->first()->get('title'));
    }

    #[Test]
    public function rss_and_atom_read_into_the_same_fields(): void
    {
        foreach (['news/rss.xml' => 'rss', 'news/atom.xml' => 'atom'] as $path => $handle) {
            $this->saveFeed($handle, [
                'source' => ['type' => 'url', 'url' => 'https://feeds.test/'.$path],
                'destination' => ['type' => 'entries', 'collection' => 'news', 'blueprint' => 'post'],
                'key' => ['_feed.id'],
                'mapping' => [
                    'title' => ['path' => '_feed.title'],
                    'link' => ['path' => '_feed.link'],
                    'date' => ['path' => '_feed.date'],
                    'body' => ['path' => '_feed.content'],
                    'categories' => ['path' => '_feed.categories'],
                ],
            ]);

            $run = $this->ingest($handle);
            $this->assertSame(15, $run->counts['created'], $handle.': '.$run->message);
        }

        $rss = Entry::query()->where('collection', 'news')->where('link', 'https://news.example.test/posts/1')->get();
        $this->assertCount(2, $rss);
        $this->assertSame($rss[0]->date()->toDateString(), $rss[1]->date()->toDateString());
        $this->assertSame('paragraph', $rss[0]->get('body')[0]['type']);
    }

    #[Test]
    public function blm_property_feeds_import(): void
    {
        $this->saveFeed('lettings', [
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/property/listings.blm'],
            'destination' => ['type' => 'entries', 'collection' => 'books', 'blueprint' => 'book'],
            'key' => ['AGENT_REF'],
            'mapping' => ['title' => ['template' => '{ADDRESS_1}, {TOWN}'], 'pages' => ['path' => 'BEDROOMS']],
        ]);

        $run = $this->ingest('lettings');

        $this->assertSame(25, $run->counts['created'], (string) $run->message);
        $this->assertMatchesRegularExpression('/^\d+ .+, .+$/', Entry::query()->where('collection', 'books')->first()->get('title'));
    }

    #[Test]
    public function a_show_endpoint_is_one_item(): void
    {
        $this->booksFeed([], 'books/show/9791001000373.json');

        $run = $this->ingest('books');

        $this->assertSame(1, $run->counts['created']);
    }

    public static function pagination(): array
    {
        return [
            'next url in the body' => ['books/paged/next-url/page-1.json', ['type' => 'next_url', 'path' => 'links.next'], 'data'],
            'page number in the path' => ['books/paged/numbered/{page}.json', ['type' => 'page', 'last_page_path' => 'meta.last_page'], 'data'],
            'offset in the path' => ['books/paged/offset/{offset}.json', ['type' => 'offset', 'size' => 25, 'total_path' => 'count'], 'results'],
            'cursor' => ['books/paged/cursor/{cursor}.json', ['type' => 'cursor', 'first' => 'start', 'path' => 'next_cursor', 'has_more_path' => 'has_more'], 'items'],
            'xml next element' => ['books/paged/xml/page-1.xml', ['type' => 'next_url', 'path' => 'catalogue/next'], 'catalogue/book'],
        ];
    }

    #[Test]
    #[DataProvider('pagination')]
    public function every_pagination_strategy_reads_every_page(string $path, array $pagination, string $itemPath): void
    {
        $this->booksFeed([
            'source' => ['pagination' => $pagination],
            'format' => ['item_path' => $itemPath],
            'mapping' => ['title' => ['path' => 'title'], 'isbn' => ['path' => 'isbn']],
        ], $path);

        $run = $this->ingest('books');

        $this->assertSame(60, $run->counts['created'], (string) $run->message);
        $this->assertSame(3, $run->payload['pages']);
        $this->assertTrue($run->payload['complete']);
    }

    #[Test]
    public function query_string_and_link_header_pagination(): void
    {
        $pages = array_chunk($this->fixture('books/index.json')['data'], 25);
        $this->serve('api/books', function (Request $request) use ($pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $headers = ['Content-Type' => 'application/json'];
            if ($page < count($pages)) {
                $headers['Link'] = '<https://feeds.test/api/books?page='.($page + 1).'>; rel="next", <https://feeds.test/api/books?page=1>; rel="first"';
            }

            return Http::response(['data' => $pages[$page - 1] ?? []], 200, $headers);
        });

        $this->booksFeed(['source' => ['pagination' => ['type' => 'link_header']]], 'api/books');
        $run = $this->ingest('books');
        $this->assertSame(60, $run->counts['created']);

        $this->booksFeed(['source' => ['pagination' => ['type' => 'page', 'param' => 'page']]], 'api/books', 'paged');
        $paged = $this->ingest('paged');
        $this->assertSame(60, $paged->total);
        // With no last page to read, the empty fourth page marks the end.
        $this->assertSame(4, $paged->payload['pages']);
    }

    #[Test]
    public function a_page_limit_stops_and_marks_the_run_incomplete(): void
    {
        $this->booksFeed([
            'source' => ['pagination' => ['type' => 'next_url', 'path' => 'links.next', 'max_pages' => 2]],
            'sync' => ['missing' => ['action' => 'delete']],
        ], 'books/paged/next-url/page-1.json');

        $run = $this->ingest('books');

        $this->assertSame(50, $run->counts['created']);
        $this->assertFalse($run->payload['complete']);
        $this->assertNotEmpty($run->payload['warnings']);
    }

    #[Test]
    public function auth_headers_come_from_the_environment(): void
    {
        putenv('SUPPLIER_TOKEN=secret-123');
        $this->serve('private.json', fn (Request $request) => $request->header('Authorization')[0] === 'Bearer secret-123'
            ? Http::response(file_get_contents(self::FEEDS.'/states/books/v1.json'), 200, ['Content-Type' => 'application/json'])
            : Http::response('No', 401));

        $this->booksFeed(['source' => ['auth' => ['type' => 'bearer', 'token' => 'env:SUPPLIER_TOKEN']]], 'private.json');

        $this->assertSame(20, $this->ingest('books')->counts['created']);
        putenv('SUPPLIER_TOKEN');
    }

    #[Test]
    public function a_missing_secret_fails_clearly(): void
    {
        $this->booksFeed(['source' => ['auth' => ['type' => 'bearer', 'token' => 'env:NOT_SET_ANYWHERE']]]);

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('NOT_SET_ANYWHERE', $run->message);
    }

    #[Test]
    public function api_keys_basic_auth_and_custom_headers(): void
    {
        putenv('KEY=k1');
        putenv('PASS=p1');
        $seen = [];
        $this->serve('auth.json', function (Request $request) use (&$seen) {
            $seen[] = [$request->url(), $request->headers()];

            return Http::response(['data' => []], 200);
        });

        foreach ([
            ['type' => 'api_key', 'name' => 'X-Api-Key', 'value' => 'env:KEY'],
            ['type' => 'api_key', 'name' => 'apikey', 'value' => 'env:KEY', 'in' => 'query'],
            ['type' => 'basic', 'username' => 'shop', 'password' => 'env:PASS'],
        ] as $i => $auth) {
            $this->booksFeed(['source' => ['auth' => $auth, 'headers' => [['name' => 'Accept', 'value' => 'application/json']]], 'sync' => ['safety' => ['allow_empty' => true]]], 'auth.json', 'auth'.$i);
            $this->ingest('auth'.$i);
        }

        $this->assertSame(['k1'], $seen[0][1]['X-Api-Key']);
        $this->assertStringContainsString('apikey=k1', $seen[1][0]);
        $this->assertSame(['Basic '.base64_encode('shop:p1')], $seen[2][1]['Authorization']);
        $this->assertSame(['application/json'], $seen[0][1]['Accept']);
        putenv('KEY');
        putenv('PASS');
    }

    #[Test]
    public function oauth_client_credentials_fetch_and_reuse_a_token(): void
    {
        putenv('CLIENT_SECRET=s3cret');
        $tokens = 0;
        $this->serve('oauth/token', function () use (&$tokens) {
            $tokens++;

            return Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200);
        });
        $this->serve('events.json', fn (Request $r) => $r->header('Authorization')[0] === 'Bearer tok-1'
            ? Http::response(file_get_contents(self::FEEDS.'/states/books/v1.json'), 200, ['Content-Type' => 'application/json'])
            : Http::response('No', 401));

        $this->booksFeed(['source' => ['auth' => ['type' => 'oauth2', 'token_url' => 'https://feeds.test/oauth/token', 'client_id' => 'shop', 'client_secret' => 'env:CLIENT_SECRET']]], 'events.json');

        $this->assertSame(20, $this->ingest('books')->counts['created']);
        $this->ingest('books', options: ['force' => true]);
        $this->assertSame(1, $tokens);
        putenv('CLIENT_SECRET');
    }

    #[Test]
    public function private_network_addresses_are_refused(): void
    {
        foreach (['http://internal.example/feed.json', 'http://127.0.0.1/feed.json', 'http://169.254.169.254/latest/meta-data', 'file:///etc/passwd', 'http://[::1]/x', 'http://user:pass@feeds.test/x'] as $url) {
            $this->booksFeed(['source' => ['url' => $url]]);
            $run = $this->ingest('books');

            $this->assertSame(RunStatus::Failed, $run->status, $url);
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    #[Test]
    public function an_allowed_internal_host_can_be_read(): void
    {
        config(['ingest.allowed_hosts' => ['*.internal.example']]);
        $this->serve('erp.json', self::FEEDS.'/states/books/v1.json');
        $this->booksFeed(['source' => ['url' => 'http://erp.internal.example/erp.json']]);

        $this->assertSame(20, $this->ingest('books')->counts['created']);
    }

    #[Test]
    public function redirects_to_private_addresses_are_refused(): void
    {
        $this->serve('moved.json', fn () => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']));
        $this->booksFeed([], 'moved.json');

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('private or internal', $run->message);
    }

    #[Test]
    public function oversized_feeds_are_refused(): void
    {
        config(['ingest.http.max_feed_size' => 0.001]);
        $this->booksFeed();

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('limit', $run->message);
    }

    #[Test]
    public function external_entities_in_xml_are_never_read(): void
    {
        $this->booksFeed([
            'format' => ['item_path' => 'catalogue/book'],
            'mapping' => ['title' => ['path' => 'title'], 'isbn' => ['path' => 'isbn']],
        ], 'states/books/xxe.xml');

        $this->ingest('books');

        $entry = Entry::query()->where('collection', 'books')->first();
        $this->assertStringNotContainsString('root:', (string) $entry?->get('title'));
    }

    #[Test]
    public function uploaded_files_and_server_paths_are_read(): void
    {
        @mkdir(FileSource::directory('upload'), 0755, true);
        copy(self::FEEDS.'/books/index.csv', FileSource::directory('upload').'/books.csv');
        $this->booksFeed(['source' => ['type' => 'upload', 'file' => 'books.csv'], 'format' => ['item_path' => null]], handle: 'upload');
        $this->assertSame(60, $this->ingest('upload')->counts['created']);

        // The same file again: nothing to do.
        $this->assertTrue($this->ingest('upload')->payload['unchanged'] ?? false);

        copy(self::FEEDS.'/states/csv/semicolon.csv', self::TMP.'/drops/stock.csv');
        $this->booksFeed(['source' => ['type' => 'path', 'path' => self::TMP.'/drops/stock.csv', 'archive' => true], 'format' => ['item_path' => null]], handle: 'drop');
        $this->assertSame(RunStatus::Completed, $this->ingest('drop')->status);
        $this->assertFileDoesNotExist(self::TMP.'/drops/stock.csv');
        $this->assertCount(1, glob(self::TMP.'/drops/archive/*-stock.csv'));
    }

    #[Test]
    public function server_paths_outside_the_allowed_folders_are_refused(): void
    {
        $this->booksFeed(['source' => ['type' => 'path', 'path' => '/etc/passwd']]);

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('ingest.server_paths', $run->message);
    }

    #[Test]
    public function public_google_sheets_are_read_as_csv(): void
    {
        $this->serve('spreadsheets/d/abc123/export', function (Request $request) {
            $this->assertStringContainsString('format=csv', $request->url());
            $this->assertStringContainsString('gid=42', $request->url());

            return Http::response(file_get_contents(self::FEEDS.'/sheets/staff-picks.csv'), 200, ['Content-Type' => 'text/csv']);
        });

        $this->saveFeed('picks', [
            'source' => ['type' => 'google_sheets', 'url' => 'https://docs.google.com/spreadsheets/d/abc123/edit#gid=42'],
            'destination' => ['type' => 'entries', 'collection' => 'books', 'blueprint' => 'book'],
            'key' => ['ISBN'],
            'mapping' => ['title' => ['path' => 'Why we love it'], 'live' => ['path' => 'Featured?']],
        ]);

        $run = $this->ingest('picks');

        $this->assertSame(10, $run->counts['created'], (string) $run->message);
        $this->assertSame(3, Entry::query()->where('collection', 'books')->where('live', true)->count());
    }

    #[Test]
    public function a_private_google_sheet_without_sharing_explains_itself(): void
    {
        $this->serve('spreadsheets/d/abc123/export', fn () => Http::response('<!doctype html><title>Sign in</title>', 200, ['Content-Type' => 'text/html']));

        $this->saveFeed('picks', [
            'source' => ['type' => 'google_sheets', 'url' => 'https://docs.google.com/spreadsheets/d/abc123/edit'],
            'destination' => ['type' => 'entries', 'collection' => 'books'],
            'key' => ['ISBN'],
        ]);

        $run = $this->ingest('picks');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Anyone with the link', $run->message);
    }

    #[Test]
    public function an_api_key_in_the_query_never_reaches_the_run_message(): void
    {
        putenv('KEY=SECRET-KEY-1');
        $this->serve('down.json', fn () => throw new ConnectionException('cURL error 7: Failed to connect for https://feeds.test/down.json?apikey=SECRET-KEY-1'));
        $this->booksFeed(['source' => ['auth' => ['type' => 'api_key', 'name' => 'apikey', 'value' => 'env:KEY', 'in' => 'query']]], 'down.json');

        $run = $this->ingest('books');

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringNotContainsString('SECRET-KEY-1', $run->message);
        putenv('KEY');
    }
}
