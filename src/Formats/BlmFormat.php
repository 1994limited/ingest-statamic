<?php

namespace NineteenNinetyFour\Ingest\Formats;

use Generator;
use NineteenNinetyFour\Ingest\Contracts\Format;
use NineteenNinetyFour\Ingest\IngestException;

/**
 * Rightmove BLM: a #HEADER# naming the field (EOF) and row (EOR)
 * separators, a #DEFINITION# listing the fields, then #DATA# rows.
 * Items are keyed by the definition's field names (AGENT_REF, PRICE…).
 */
class BlmFormat implements Format
{
    public static function handle(): string
    {
        return 'blm';
    }

    public static function label(): string
    {
        return 'Rightmove BLM';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        return $extension === 'blm' || str_starts_with(ltrim($head), '#HEADER#') ? 100 : 0;
    }

    public function defaultItemPath(): ?string
    {
        return null;
    }

    public function items(string $file, array $options): Generator
    {
        $contents = (string) file_get_contents($file);
        $contents = str_replace("\r\n", "\n", ltrim($contents, "\xEF\xBB\xBF"));

        if (! preg_match('/#HEADER#(.*?)#DEFINITION#(.*?)#DATA#(.*?)(#END#|$)/s', $contents, $m)) {
            throw new IngestException('This doesn\'t look like a BLM file: #HEADER#, #DEFINITION# and #DATA# sections are needed.');
        }

        $eof = $this->separator($m[1], 'EOF') ?? '^';
        $eor = $this->separator($m[1], 'EOR') ?? '~';

        $fields = array_values(array_filter(array_map('trim', explode($eof, trim(explode($eor, trim($m[2]))[0]))), fn ($f) => $f !== ''));

        if ($fields === []) {
            throw new IngestException('The BLM file\'s #DEFINITION# has no fields.');
        }

        foreach (explode($eor, $m[3]) as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }

            $values = explode($eof, $row);
            $item = [];
            foreach ($fields as $i => $field) {
                $item[$field] = trim($values[$i] ?? '');
            }

            yield $item;
        }
    }

    public function document(string $file, array $options): array
    {
        return iterator_to_array($this->items($file, $options), false);
    }

    private function separator(string $header, string $name): ?string
    {
        return preg_match("/{$name}\s*:\s*'(.)'/", $header, $m) ? $m[1] : null;
    }
}
