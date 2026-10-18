<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Generator;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Map\MapRecord;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;

/**
 * A run's items, read once from the fetched pages and written one per line
 * (items.ndjson), with where each chunk starts (chunks.json). Queued jobs
 * then jump straight to their chunk instead of re-reading the feed, and a
 * dry run's items can be applied exactly as previewed.
 *
 * Each line: {"p": position, "k": key hash, "s": key as text, "d": duplicate, "i": item}
 */
class ItemsFile
{
    public function __construct(private string $directory) {}

    public function path(): string
    {
        return $this->directory.'/items.ndjson';
    }

    /**
     * Write the items, noting chunk starts and duplicate keys.
     *
     * @param  iterable<array<string, mixed>>  $items
     * @param  string[]  $keyPaths
     * @return int how many items were written
     */
    public function write(iterable $items, array $keyPaths, int $chunkSize, ?int $limit = null, ?string $fixedKey = null): int
    {
        File::ensureDirectoryExists($this->directory);

        $out = fopen($this->path(), 'wb');
        $offsets = [];
        $seen = [];
        $position = 0;

        try {
            foreach ($items as $item) {
                if ($limit !== null && $position >= $limit) {
                    break;
                }

                if ($position % $chunkSize === 0) {
                    $offsets[] = ftell($out);
                }

                $values = $fixedKey !== null ? [$fixedKey] : array_map(fn ($path) => trim(Value::text(Path::get($item, $path))), $keyPaths);
                $empty = $values === [] || in_array('', $values, true);
                $hash = $empty ? null : MapRecord::hashKey($values);
                $duplicate = $hash !== null && isset($seen[$hash]);

                if ($hash !== null) {
                    $seen[$hash] = true;
                }

                fwrite($out, json_encode([
                    'p' => $position,
                    'k' => $hash,
                    's' => $empty ? null : implode(' / ', $values),
                    'd' => $duplicate,
                    'i' => $item,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");

                $position++;
            }
        } finally {
            fclose($out);
        }

        file_put_contents($this->directory.'/chunks.json', json_encode($offsets));

        return $position;
    }

    public function chunks(): int
    {
        return count($this->offsets());
    }

    /**
     * @return Generator<int, array{p: int, k: ?string, s: ?string, d: bool, i: array}>
     */
    public function chunk(int $index): Generator
    {
        $offsets = $this->offsets();

        if (! isset($offsets[$index]) || ! is_file($this->path())) {
            return;
        }

        $end = $offsets[$index + 1] ?? null;
        $handle = fopen($this->path(), 'rb');

        try {
            fseek($handle, $offsets[$index]);

            while (($end === null || ftell($handle) < $end) && ($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Keys of items the run kept (passed filters), for the missing-items step.
     *
     * @param  string[]  $keys
     */
    public function markSeen(array $keys): void
    {
        if ($keys !== []) {
            file_put_contents($this->directory.'/seen.txt', implode("\n", $keys)."\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** @return array<string, true> */
    public function seen(): array
    {
        $path = $this->directory.'/seen.txt';

        if (! is_file($path)) {
            return [];
        }

        $seen = [];
        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $seen[$line] = true;
            }
        }
        fclose($handle);

        return $seen;
    }

    /**
     * Content changed by the run, for search, the static cache and Git at the end.
     */
    public function markChanged(string $type, string $id, string $action): void
    {
        file_put_contents($this->directory.'/changed.txt', "{$type}|{$id}|{$action}\n", FILE_APPEND | LOCK_EX);
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
    public function changed(): array
    {
        $path = $this->directory.'/changed.txt';

        return is_file($path)
            ? array_values(array_filter(array_map(fn ($l) => explode('|', trim($l)), file($path) ?: []), fn ($p) => count($p) === 3))
            : [];
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Start over for a re-applied dry run: keep the items, forget progress.
     */
    public function resetProgress(): void
    {
        File::delete([$this->directory.'/seen.txt', $this->directory.'/changed.txt']);
    }

    /** @return int[] */
    private function offsets(): array
    {
        $path = $this->directory.'/chunks.json';

        return is_file($path) ? array_map('intval', (array) json_decode((string) file_get_contents($path), true)) : [];
    }
}
