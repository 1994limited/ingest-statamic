<?php

namespace NineteenNinetyFour\Ingest\Formats;

use Generator;
use NineteenNinetyFour\Ingest\Contracts\Format;

/**
 * CSV and TSV, read a row at a time.
 *
 * Options: delimiter (auto, ",", ";", "\t", "|"), enclosure, header (the
 * first row names the columns; without it columns are A, B, C… as in a
 * spreadsheet), skip_rows, encoding (auto, UTF-8, Windows-1252, ISO-8859-1).
 */
class CsvFormat implements Format
{
    public static function handle(): string
    {
        return 'csv';
    }

    public static function label(): string
    {
        return 'CSV / TSV';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        if (in_array($extension, ['csv', 'tsv'], true) || in_array($contentType, ['text/csv', 'text/tab-separated-values'], true)) {
            return 90;
        }

        $first = strtok(ltrim($head, "\xEF\xBB\xBF"), "\n");

        return $first !== false && self::guessDelimiter($first) !== null ? 30 : 0;
    }

    public function defaultItemPath(): ?string
    {
        return null;
    }

    public function items(string $file, array $options): Generator
    {
        $handle = fopen($file, 'rb');

        try {
            $head = (string) fread($handle, 64 * 1024);
            rewind($handle);

            if (str_starts_with($head, "\xEF\xBB\xBF")) {
                fseek($handle, 3);
                $head = substr($head, 3);
            }

            $encoding = $options['encoding'] ?? 'auto';
            if ($encoding === 'auto') {
                $encoding = mb_check_encoding($head, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
            }

            $delimiter = $options['delimiter'] ?? 'auto';
            $delimiter = $delimiter === '\t' ? "\t" : $delimiter;
            if ($delimiter === 'auto' || $delimiter === '' || $delimiter === null) {
                $delimiter = self::guessDelimiter((string) strtok($head, "\n")) ?? ',';
            }

            $enclosure = $options['enclosure'] ?? '"';
            $hasHeader = (bool) ($options['header'] ?? true);
            $skip = max(0, (int) ($options['skip_rows'] ?? 0));

            for ($i = 0; $i < $skip; $i++) {
                fgetcsv($handle, null, $delimiter, $enclosure, '');
            }

            $columns = null;

            while (($row = fgetcsv($handle, null, $delimiter, $enclosure, '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                if ($encoding !== 'UTF-8') {
                    $row = array_map(fn ($v) => $v === null ? null : mb_convert_encoding($v, 'UTF-8', $encoding), $row);
                }

                if ($columns === null) {
                    if ($hasHeader) {
                        $columns = self::columns($row);

                        continue;
                    }
                    $columns = array_map(self::letter(...), array_keys($row));
                }

                // Rows longer than the header get letters for the extra cells.
                $item = [];
                foreach ($row as $i => $value) {
                    $item[$columns[$i] ?? self::letter($i)] = $value === null ? null : trim($value);
                }

                if (implode('', array_map('strval', $item)) === '') {
                    continue;
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

    public static function guessDelimiter(string $line): ?string
    {
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($line, $candidate);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : null;
    }

    /** @return string[] */
    private static function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $i => $name) {
            $name = trim((string) $name);
            $name = $name === '' ? self::letter($i) : $name;
            $base = $name;
            $n = 2;
            while (in_array($name, $columns, true)) {
                $name = $base.'_'.$n++;
            }
            $columns[] = $name;
        }

        return $columns;
    }

    public static function letter(int $index): string
    {
        $letter = '';
        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr(65 + $i % 26).$letter;
        }

        return $letter;
    }
}
