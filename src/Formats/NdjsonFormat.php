<?php

namespace NineteenNinetyFour\Ingest\Formats;

use Generator;
use NineteenNinetyFour\Ingest\Contracts\Format;
use NineteenNinetyFour\Ingest\IngestException;

/**
 * Newline-delimited JSON (JSON Lines): one item per line.
 */
class NdjsonFormat implements Format
{
    public static function handle(): string
    {
        return 'ndjson';
    }

    public static function label(): string
    {
        return 'JSON Lines (NDJSON)';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        if (in_array($extension, ['ndjson', 'jsonl'], true) || in_array($contentType, ['application/x-ndjson', 'application/jsonl'], true)) {
            return 100;
        }

        $lines = array_values(array_filter(explode("\n", ltrim($head)), fn ($l) => trim($l) !== ''));

        // Two lines that each start an object, and the first is a whole object.
        if (count($lines) >= 2 && str_starts_with(trim($lines[0]), '{') && str_starts_with(trim($lines[1]), '{') && json_decode(trim($lines[0])) !== null) {
            return 90;
        }

        return 0;
    }

    public function defaultItemPath(): ?string
    {
        return null;
    }

    public function items(string $file, array $options): Generator
    {
        $handle = fopen($file, 'rb');
        $line = 0;

        try {
            while (($row = fgets($handle)) !== false) {
                $line++;
                $row = trim($row, "\xEF\xBB\xBF \t\r\n");

                if ($row === '') {
                    continue;
                }

                $item = json_decode($row, true);

                if (! is_array($item)) {
                    throw new IngestException("Line {$line} isn't a JSON object.");
                }

                yield $item;
            }
        } finally {
            fclose($handle);
        }
    }

    public function document(string $file, array $options): array
    {
        return iterator_to_array($this->items($file, $options), false);
    }
}
