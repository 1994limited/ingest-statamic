<?php

namespace NineteenNinetyFour\Ingest\Formats;

use Generator;
use NineteenNinetyFour\Ingest\Support\Path;

/**
 * The items at a path in a decoded document: a list gives its items, a
 * single object gives one item (a "show" endpoint), nothing gives none.
 */
class ItemsAt
{
    /** @return Generator<int, array<string, mixed>> */
    public static function of(array $document, string $path): Generator
    {
        $value = trim($path) === '' ? $document : Path::get($document, $path);

        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (! is_array($value)) {
            yield ['value' => $value];

            return;
        }

        if (! Path::isList($value)) {
            yield $value;

            return;
        }

        foreach ($value as $item) {
            if ($item === null) {
                continue;
            }

            yield is_array($item) ? $item : ['value' => $item];
        }
    }
}
