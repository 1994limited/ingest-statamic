<?php

namespace NineteenNinetyFour\Ingest;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use NineteenNinetyFour\Ingest\Commands\CheckFeed;
use NineteenNinetyFour\Ingest\Commands\ListFeeds;
use NineteenNinetyFour\Ingest\Commands\PruneRuns;
use NineteenNinetyFour\Ingest\Commands\RunFeed;
use NineteenNinetyFour\Ingest\Commands\ScheduleFeeds;
use NineteenNinetyFour\Ingest\Commands\TransferData;
use NineteenNinetyFour\Ingest\Commands\UndoRun;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Database\DatabaseFeedRepository;
use NineteenNinetyFour\Ingest\Database\DatabaseItemMap;
use NineteenNinetyFour\Ingest\Database\DatabaseRunRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Feeds\FileFeedRepository;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Integrations\Cargo\Cargo;
use NineteenNinetyFour\Ingest\Integrations\Cargo\MoneyMapper;
use NineteenNinetyFour\Ingest\Integrations\Cargo\ProductVariantsMapper;
use NineteenNinetyFour\Ingest\Map\FileItemMap;
use NineteenNinetyFour\Ingest\Mapping\FieldMappers;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use NineteenNinetyFour\Ingest\Runs\FileRunRepository;
use NineteenNinetyFour\Ingest\Sources\Sources;
use NineteenNinetyFour\Ingest\Widgets\FeedHealth;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    /** The Control Panel nav icon. */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path d="M2 2.5h10M2 5h6" stroke="currentColor" stroke-linecap="round"/><path d="M7 7.5v4m0 0L5 9.5m2 2 2-2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.5 12.5h9" stroke="currentColor" stroke-linecap="round"/></svg>';

    protected $commands = [
        RunFeed::class,
        ListFeeds::class,
        CheckFeed::class,
        UndoRun::class,
        PruneRuns::class,
        ScheduleFeeds::class,
        TransferData::class,
    ];

    protected $widgets = [
        FeedHealth::class,
    ];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    /**
     * The package is 1994/ingest-statamic; config, views and translations
     * are read as plain "ingest", so they're loaded by hand. Routes too:
     * actions live at /!/ingest.
     */
    protected $config = false;

    protected $translations = false;

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/ingest.php', 'ingest');

        $this->app->singleton(Ingest::class);
        $this->app->singleton(Formats::class);
        $this->app->singleton(Sources::class);
        $this->app->singleton(Destinations::class);
        $this->app->singleton(Transforms::class);
        $this->app->singleton(FieldMappers::class);

        $this->app->singleton(FeedRepository::class, fn () => match ($this->driver()) {
            'file' => new FileFeedRepository((string) config('ingest.drivers.file.feeds')),
            'database' => new DatabaseFeedRepository,
        });

        $this->app->singleton(RunRepository::class, fn () => match ($this->driver()) {
            'file' => new FileRunRepository(Ingest::storagePath('runs')),
            'database' => new DatabaseRunRepository,
        });

        $this->app->singleton(ItemMap::class, fn () => match ($this->driver()) {
            'file' => new FileItemMap(Ingest::storagePath('map')),
            'database' => new DatabaseItemMap,
        });
    }

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ingest');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ingest');

        $this->publishes([__DIR__.'/../config/ingest.php' => config_path('ingest.php')], 'ingest-config');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'ingest-migrations');

        if ($this->driver() === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (Cargo::installed()) {
            $mappers = $this->app->make(FieldMappers::class);
            $mappers->register('money', MoneyMapper::class);
            $mappers->register('product_variants', ProductVariantsMapper::class);
        }

        Statamic::pushActionRoutes(function () {
            Route::prefix('ingest')->name('ingest.')->group(__DIR__.'/../routes/actions.php');
        });

        // The webhook is authenticated by its token, so it needs no CSRF
        // token. VerifyCsrfToken's list is shared by Laravel 12's
        // ValidateCsrfToken and Laravel 13's PreventRequestForgery.
        $actions = trim((string) config('statamic.routes.action', '!'), '/');
        VerifyCsrfToken::except([$actions.'/ingest/feeds/*/run']);

        $this->permissions();
        $this->nav();

        if (config('ingest.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('ingest:schedule')->everyMinute()->withoutOverlapping()->runInBackground();
                $schedule->command('ingest:prune')->daily();
            });
        }

        Settings::apply();
        Event::listen(AddonSettingsSaved::class, fn () => Settings::apply());
    }

    private function driver(): string
    {
        $driver = (string) config('ingest.driver', 'file');

        if (! in_array($driver, ['file', 'database'], true)) {
            throw new InvalidArgumentException("Unknown Ingest driver [{$driver}]. Use file or database.");
        }

        return $driver;
    }

    private function permissions(): void
    {
        Permission::extend(function () {
            Permission::group('ingest', __('Ingest'), function () {
                Permission::register('view ingest', function ($permission) {
                    $permission
                        ->label(__('View feeds and runs'))
                        ->children([
                            Permission::make('run ingest feeds')->label(__('Run feeds')),
                            Permission::make('manage ingest feeds')->label(__('Create and edit feeds')),
                            Permission::make('undo ingest runs')->label(__('Undo runs')),
                        ]);
                });
            });
        });
    }

    private function nav(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools(__('Ingest'))
                ->route('ingest.index')
                ->icon(self::ICON)
                ->can('view ingest');
        });
    }
}
