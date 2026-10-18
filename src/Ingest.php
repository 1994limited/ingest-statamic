<?php

namespace NineteenNinetyFour\Ingest;

use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\Format;
use NineteenNinetyFour\Ingest\Contracts\Source;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Hooks\FeedHook;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\FieldMappers;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\Runner;
use NineteenNinetyFour\Ingest\Sources\Sources;

/**
 * Ingest's PHP API (the Ingest facade).
 *
 *     Ingest::run('supplier');                       // queue a run
 *     Ingest::run('supplier', dryRun: true);
 *     Ingest::transform('add_vat', fn ($v) => $v * 1.2);
 *     Ingest::hook('supplier', SupplierHook::class);
 *     Ingest::source(MySource::class);
 *     Ingest::format(MyFormat::class);
 *     Ingest::destination(MyDestination::class);
 *     Ingest::fieldMapper('my_fieldtype', MyMapper::class);
 */
class Ingest
{
    /** @var array<string, class-string<FeedHook>> */
    private array $hooks = [];

    public function feeds(): FeedRepository
    {
        return app(FeedRepository::class);
    }

    public function feed(string $handle): ?Feed
    {
        return $this->feeds()->find($handle);
    }

    public function run(string|Feed $feed, bool $dryRun = false, string $trigger = 'manual', array $options = []): Run
    {
        $feed = $feed instanceof Feed ? $feed : $this->feed($feed);

        if (! $feed) {
            throw new IngestException('There\'s no feed with that handle.');
        }

        return app(Runner::class)->start($feed, $trigger, $dryRun, $options, auth()->id());
    }

    public function transform(string $name, callable $transform): void
    {
        app(Transforms::class)->register($name, $transform);
    }

    /** @param class-string<FeedHook> $class */
    public function hook(string $name, string $class): void
    {
        $this->hooks[$name] = $class;
    }

    /** @return array<string, class-string<FeedHook>> */
    public function hooks(): array
    {
        return $this->hooks;
    }

    public function hookFor(Feed $feed): ?FeedHook
    {
        $name = $feed->get('hook');

        if (! $name) {
            return null;
        }

        if (! isset($this->hooks[$name])) {
            throw new IngestException("The feed uses the hook \"{$name}\", but no hook has that name. Register it with Ingest::hook().");
        }

        return app($this->hooks[$name]);
    }

    /** @param class-string<Source> $class */
    public function source(string $class): void
    {
        app(Sources::class)->register($class);
    }

    /** @param class-string<Format> $class */
    public function format(string $class): void
    {
        app(Formats::class)->register($class);
    }

    /** @param class-string<Destination> $class */
    public function destination(string $class): void
    {
        app(Destinations::class)->register($class);
    }

    /** @param class-string<FieldMapper> $class */
    public function fieldMapper(string $fieldtype, string $class): void
    {
        app(FieldMappers::class)->register($fieldtype, $class);
    }

    /**
     * Where runtime files go: payloads, run files, the map, uploads.
     */
    public static function storagePath(string $path = ''): string
    {
        $driver = config('ingest.driver', 'file');
        $base = rtrim((string) config("ingest.drivers.{$driver}.storage", storage_path('ingest')), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }
}
