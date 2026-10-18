<?php

namespace NineteenNinetyFour\Ingest\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Http\UrlGuard;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\Runner;
use NineteenNinetyFour\Ingest\ServiceProvider;
use NineteenNinetyFour\Ingest\Settings;
use NineteenNinetyFour\Ingest\Support\Secrets;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** The storage driver the test runs with. */
    protected string $driver = 'file';

    /** Feed files served at https://feeds.test/… (tests/__fixtures__/feeds). */
    public const FEEDS = __DIR__.'/__fixtures__/feeds';

    public const TMP = __DIR__.'/__fixtures__/tmp';

    /** @var array<string, string|callable> url path => file path or a response */
    protected array $routes = [];

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ingest.driver', $this->driver);
        $app['config']->set('ingest.drivers.file.feeds', self::TMP.'/feeds');
        $app['config']->set('ingest.drivers.file.storage', self::TMP.'/ingest');
        $app['config']->set('ingest.drivers.database.storage', self::TMP.'/ingest');
        $app['config']->set('ingest.server_paths', [self::TMP.'/drops']);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('filesystems.disks.test_assets', ['driver' => 'local', 'root' => self::TMP.'/assets', 'url' => '/assets']);
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(self::TMP);
        File::ensureDirectoryExists(self::TMP.'/drops');
        Settings::flush();
        Secrets::flush();
        Cache::flush();

        if ($this->driver === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Every host resolves to a public address, except ones named "internal".
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => str_contains($host, 'internal') ? ['10.0.0.5'] : ['93.184.216.34']));

        $this->fakeFeeds();
        $this->content();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(self::TMP);
        File::deleteDirectory(resource_path('blueprints'));
        File::delete(resource_path('addons/ingest-statamic.yaml'));
        Settings::flush();

        parent::tearDown();
    }

    /**
     * https://feeds.test/{path} serves tests/__fixtures__/feeds/{path};
     * $this->routes overrides paths (a file path, or a closure returning a response).
     */
    protected function fakeFeeds(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $host = (string) parse_url($url, PHP_URL_HOST);

            if (isset($this->routes[$path])) {
                $route = $this->routes[$path];

                return is_callable($route) ? $route($request) : Http::response(File::get($route), 200, $this->headers($route));
            }

            if ($host === 'feeds.test' && is_file(self::FEEDS.'/'.$path)) {
                return Http::response(File::get(self::FEEDS.'/'.$path), 200, $this->headers($path));
            }

            return Http::response('Not found', 404);
        });
    }

    private function headers(string $path): array
    {
        $type = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'json' => 'application/json',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'png' => 'image/png',
            default => 'text/plain',
        };

        return ['Content-Type' => $type];
    }

    /**
     * The Dog-ear Books shape: books (products), authors, news, jobs,
     * subjects, an asset container.
     */
    protected function content(): void
    {
        Collection::make('books')->routes('/books/{slug}')->save();
        Collection::make('authors')->routes('/authors/{slug}')->save();
        Collection::make('news')->routes('/news/{slug}')->dated(true)->save();
        Collection::make('jobs')->routes('/jobs/{slug}')->save();
        Taxonomy::make('subjects')->save();
        AssetContainer::make('assets')->disk('test_assets')->save();

        $this->blueprint('collections.books', 'book', [
            'title' => ['type' => 'text'],
            'isbn' => ['type' => 'text'],
            'subtitle' => ['type' => 'text'],
            'description' => ['type' => 'bard', 'buttons' => ['bold', 'italic', 'anchor']],
            'blurb' => ['type' => 'markdown'],
            'pages' => ['type' => 'integer'],
            'price' => ['type' => 'float'],
            'published_on' => ['type' => 'date'],
            'live' => ['type' => 'toggle'],
            'format' => ['type' => 'select', 'options' => ['hardback' => 'Hardback', 'paperback' => 'Paperback', 'ebook' => 'Ebook']],
            'authors' => ['type' => 'entries', 'collections' => ['authors']],
            'subjects' => ['type' => 'terms', 'taxonomies' => ['subjects']],
            'cover' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1],
            'keywords' => ['type' => 'list'],
            'formats' => ['type' => 'grid', 'fields' => [
                ['handle' => 'sku', 'field' => ['type' => 'text']],
                ['handle' => 'binding', 'field' => ['type' => 'text']],
                ['handle' => 'price', 'field' => ['type' => 'float']],
                ['handle' => 'note', 'field' => ['type' => 'text']],
            ]],
            'blocks' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'text' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
                'quote' => ['fields' => [['handle' => 'quote', 'field' => ['type' => 'text']], ['handle' => 'cite', 'field' => ['type' => 'text']]]],
            ]]]],
            'meta' => ['type' => 'group', 'fields' => [
                ['handle' => 'publisher', 'field' => ['type' => 'text']],
                ['handle' => 'status', 'field' => ['type' => 'text']],
            ]],
        ]);

        $this->blueprint('collections.authors', 'author', ['title' => ['type' => 'text']]);
        $this->blueprint('collections.news', 'post', [
            'title' => ['type' => 'text'],
            'link' => ['type' => 'text'],
            'body' => ['type' => 'bard'],
            'summary' => ['type' => 'textarea'],
            'image' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1],
            'categories' => ['type' => 'list'],
        ]);
        $this->blueprint('taxonomies.subjects', 'subject', ['title' => ['type' => 'text'], 'description' => ['type' => 'textarea']]);
    }

    protected function blueprint(string $namespace, string $handle, array $fields): void
    {
        Blueprint::make($handle)->setNamespace($namespace)->setContents(['tabs' => ['main' => ['sections' => [[
            'fields' => collect($fields)->map(fn ($field, $h) => ['handle' => $h, 'field' => $field])->values()->all(),
        ]]]]])->save();
    }

    /**
     * A books feed: JSON from https://feeds.test/{path}, into the books
     * collection, keyed on ISBN.
     */
    protected function booksFeed(array $overrides = [], string $path = 'states/books/v1.json', string $handle = 'books'): Feed
    {
        $feed = Feed::make($handle, array_replace_recursive([
            'title' => 'Books',
            'source' => ['type' => 'url', 'url' => 'https://feeds.test/'.$path],
            'format' => ['type' => 'auto', 'item_path' => 'data'],
            'destination' => ['type' => 'entries', 'collection' => 'books', 'blueprint' => 'book'],
            'key' => ['isbn'],
            'mapping' => [
                'title' => ['path' => 'title'],
                'isbn' => ['path' => 'isbn'],
                'pages' => ['path' => 'pages'],
                'price' => ['path' => 'formats.1.price'],
            ],
        ], $overrides));

        if (array_key_exists('mapping', $overrides)) {
            $feed->set('mapping', $overrides['mapping']);
        }

        app(FeedRepository::class)->save($feed);

        return $feed;
    }

    protected function ingest(Feed|string $feed, bool $dry = false, array $options = []): Run
    {
        $feed = is_string($feed) ? app(FeedRepository::class)->find($feed) : $feed;

        return app(Runner::class)->start($feed, 'cli', $dry, $options + ['sync' => true]);
    }

    protected function runs(): RunRepository
    {
        return app(RunRepository::class);
    }

    protected function map(): ItemMap
    {
        return app(ItemMap::class);
    }

    protected function items(Run $run, ?string $action = null): array
    {
        return $this->runs()->items($run, $action ? ['action' => $action] : [], 1000)['items']->all();
    }

    /** Point a feed at another fixture. */
    protected function point(string $handle, string $path): Feed
    {
        $feed = app(FeedRepository::class)->find($handle);
        $feed->set('source.url', 'https://feeds.test/'.$path);
        app(FeedRepository::class)->save($feed);

        return $feed;
    }

    protected function serve(string $path, string|callable $response): void
    {
        $this->routes[$path] = $response;
    }

    protected function fixture(string $path): array
    {
        return json_decode(File::get(self::FEEDS.'/'.$path), true);
    }

    protected function uniqueHandle(): string
    {
        return 'feed_'.Str::lower(Str::random(6));
    }
}
