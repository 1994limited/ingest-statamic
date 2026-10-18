<?php

namespace NineteenNinetyFour\Ingest\Map;

use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;

/**
 * The ownership map as JSON files, split 4,096 ways by key so a feed of a
 * few hundred thousand items never loads more than a small file at once:
 * storage/ingest/map/{feed}/{site}/{000-fff}.json.
 *
 * Shards are held in memory while a chunk of items is processed and
 * written by flush().
 */
class FileItemMap implements ItemMap
{
    /** @var array<string, array<string, array>> path => key => record */
    private array $shards = [];

    /** @var array<string, bool> */
    private array $dirty = [];

    /** @var array<string, array>|null content id => record, built on first use */
    private ?array $byContent = null;

    public function __construct(private string $directory) {}

    public function find(string $feed, string $site, string $key): ?MapRecord
    {
        $data = $this->shard($feed, $site, $key)[$key] ?? null;

        return $data ? MapRecord::fromArray($data) : null;
    }

    public function forContent(string $contentId): ?MapRecord
    {
        if ($this->byContent === null) {
            $this->byContent = [];
            foreach ($this->records($this->directory) as $record) {
                $this->byContent[$record->contentId] = $record->toArray();
            }
        }

        return isset($this->byContent[$contentId]) ? MapRecord::fromArray($this->byContent[$contentId]) : null;
    }

    public function put(MapRecord $record): void
    {
        $path = $this->path($record->feed, $record->site, $record->key);
        $this->shard($record->feed, $record->site, $record->key);
        $this->shards[$path][$record->key] = $record->toArray();
        $this->dirty[$path] = true;

        if ($this->byContent !== null) {
            $this->byContent[$record->contentId] = $record->toArray();
        }
    }

    public function delete(MapRecord $record): void
    {
        $path = $this->path($record->feed, $record->site, $record->key);
        $this->shard($record->feed, $record->site, $record->key);
        unset($this->shards[$path][$record->key]);
        $this->dirty[$path] = true;

        if ($this->byContent !== null) {
            unset($this->byContent[$record->contentId]);
        }
    }

    public function missing(string $feed, string $site, string $runId): LazyCollection
    {
        $this->flush();

        return $this->records($this->directory.'/'.$feed.'/'.$site)
            ->filter(fn (MapRecord $r) => $r->lastSeenRunId !== $runId);
    }

    public function count(string $feed, ?string $site = null): int
    {
        $this->flush();

        $dir = $this->directory.'/'.$feed.($site ? '/'.$site : '');

        return $this->records($dir)->count();
    }

    public function all(?string $feed = null): LazyCollection
    {
        $this->flush();

        return $this->records($feed ? $this->directory.'/'.$feed : $this->directory);
    }

    public function forget(string $feed): void
    {
        $this->flush();
        $this->byContent = null;
        File::deleteDirectory($this->directory.'/'.$feed);
    }

    public function flush(): void
    {
        foreach (array_keys($this->dirty) as $path) {
            File::ensureDirectoryExists(dirname($path));

            if (($this->shards[$path] ?? []) === []) {
                File::delete($path);

                continue;
            }

            $tmp = $path.'.'.getmypid().'.tmp';
            file_put_contents($tmp, json_encode($this->shards[$path], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            rename($tmp, $path);
        }

        // Queue workers live on between runs: read fresh next time.
        $this->shards = [];
        $this->dirty = [];
        $this->byContent = null;
    }

    /** @return LazyCollection<int, MapRecord> */
    private function records(string $dir): LazyCollection
    {
        return LazyCollection::make(function () use ($dir) {
            if (! is_dir($dir)) {
                return;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }

                foreach ((array) json_decode((string) file_get_contents($file->getPathname()), true) as $data) {
                    yield MapRecord::fromArray($data);
                }
            }
        });
    }

    private function &shard(string $feed, string $site, string $key): array
    {
        $path = $this->path($feed, $site, $key);

        if (! array_key_exists($path, $this->shards)) {
            $this->shards[$path] = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        }

        return $this->shards[$path];
    }

    private function path(string $feed, string $site, string $key): string
    {
        return $this->directory.'/'.$feed.'/'.$site.'/'.substr($key, 0, 3).'.json';
    }
}
