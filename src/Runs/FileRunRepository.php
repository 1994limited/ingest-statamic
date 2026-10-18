<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;

/**
 * Runs as files: storage/ingest/runs/{feed}/{run id}/run.json, with each
 * item the run touched as a line of items.jsonl. Run ids are ULIDs, so
 * sorting folder names sorts by time.
 */
class FileRunRepository implements RunRepository
{
    public function __construct(private string $directory) {}

    public function find(string $id): ?Run
    {
        foreach (glob($this->directory.'/*/'.basename($id).'/run.json') ?: [] as $path) {
            return $this->read($path);
        }

        return null;
    }

    public function save(Run $run): void
    {
        $dir = $this->dir($run);
        File::ensureDirectoryExists($dir);

        // Write then rename, so a reader never sees half a file.
        $tmp = $dir.'/run.json.'.getmypid().'.tmp';
        File::put($tmp, json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $dir.'/run.json');
    }

    public function delete(Run $run): void
    {
        File::deleteDirectory($this->dir($run));
    }

    public function forFeed(string $feed, int $limit = 20, int $offset = 0): Collection
    {
        return collect($this->ids($feed))
            ->slice($offset, $limit)
            ->map(fn ($id) => $this->read($this->directory.'/'.$feed.'/'.$id.'/run.json'))
            ->filter()
            ->values();
    }

    public function countForFeed(string $feed): int
    {
        return count($this->ids($feed));
    }

    public function latest(int $limit = 20): Collection
    {
        $paths = glob($this->directory.'/*/*/run.json') ?: [];
        usort($paths, fn ($a, $b) => strcmp(basename(dirname($b)), basename(dirname($a))));

        return collect(array_slice($paths, 0, $limit))->map(fn ($p) => $this->read($p))->filter()->values();
    }

    public function active(string $feed): ?Run
    {
        foreach (array_slice($this->ids($feed), 0, 5) as $id) {
            $run = $this->read($this->directory.'/'.$feed.'/'.$id.'/run.json');
            if ($run && $run->status->active()) {
                return $run;
            }
        }

        return null;
    }

    public function addItems(Run $run, array $items): void
    {
        if ($items === []) {
            return;
        }

        File::ensureDirectoryExists($this->dir($run));

        $lines = '';
        foreach ($items as $item) {
            $lines .= json_encode($item->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        }

        file_put_contents($this->dir($run).'/items.jsonl', $lines, FILE_APPEND | LOCK_EX);
    }

    public function items(Run $run, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $actions = (array) ($filters['action'] ?? []);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $matching = $this->allItems($run)->filter(function (RunItem $item) use ($actions, $search) {
            if ($actions !== [] && ! in_array($item->action, $actions, true)) {
                return false;
            }

            return $search === '' || str_contains(strtolower($item->key.' '.$item->title), $search);
        });

        $total = 0;
        $page = [];
        foreach ($matching as $item) {
            if ($total >= $offset && count($page) < $limit) {
                $page[] = $item;
            }
            $total++;
        }

        return ['items' => collect($page), 'total' => $total];
    }

    public function allItems(Run $run): LazyCollection
    {
        $path = $this->dir($run).'/items.jsonl';

        return LazyCollection::make(function () use ($path) {
            if (! is_file($path)) {
                return;
            }

            $handle = fopen($path, 'rb');
            try {
                while (($line = fgets($handle)) !== false) {
                    $data = json_decode($line, true);
                    if (is_array($data)) {
                        yield RunItem::fromArray($data);
                    }
                }
            } finally {
                fclose($handle);
            }
        });
    }

    public function forgetItems(Run $run): void
    {
        File::delete($this->dir($run).'/items.jsonl');
    }

    public function all(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach (glob($this->directory.'/*/*/run.json') ?: [] as $path) {
                if ($run = $this->read($path)) {
                    yield $run;
                }
            }
        });
    }

    /** @return string[] newest first */
    private function ids(string $feed): array
    {
        $dirs = glob($this->directory.'/'.basename($feed).'/*', GLOB_ONLYDIR) ?: [];
        $ids = array_map('basename', $dirs);
        rsort($ids);

        return $ids;
    }

    private function dir(Run $run): string
    {
        return $this->directory.'/'.$run->feed.'/'.$run->id;
    }

    private function read(string $path): ?Run
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? Run::fromArray($data) : null;
    }
}
