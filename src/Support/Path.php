<?php

namespace NineteenNinetyFour\Ingest\Support;

/**
 * Reads values out of a feed item with a path.
 *
 *   title                  a key
 *   images.0.url           nested keys and list positions
 *   formats.*.sku          every item of a list
 *   prices.~               every value of a keyed object
 *
 *   @id                    an XML attribute (stored as "@id")
 *
 * "/" works as a separator too, so XML paths can be written naturally
 * (catalogue/book). XML gives one child as a value and several as a list,
 * so "*" treats a single value as a list of one; that way formats.*.sku
 * works whether a book has one format or three.
 */
class Path
{
    /** @return string[] */
    public static function segments(string $path): array
    {
        $path = trim(str_replace('/', '.', $path), '. ');

        return $path === '' ? [] : explode('.', $path);
    }

    public static function has(mixed $data, string $path): bool
    {
        return self::walk($data, self::segments($path), $found) !== null || $found;
    }

    /**
     * The value at the path. A path with "*" or "~" returns a list.
     */
    public static function get(mixed $data, string $path, mixed $default = null): mixed
    {
        $value = self::walk($data, self::segments($path), $found);

        return $found ? $value : $default;
    }

    /**
     * The value at the path as a list: a single value becomes a list of one,
     * nothing becomes an empty list.
     *
     * @return array<int, mixed>
     */
    public static function many(mixed $data, string $path): array
    {
        $value = self::get($data, $path);

        if ($value === null || $value === '') {
            return [];
        }

        return self::isList($value) ? array_values(array_filter($value, fn ($v) => $v !== null && $v !== '')) : [$value];
    }

    public static function isWild(string $path): bool
    {
        return in_array('*', self::segments($path), true) || in_array('~', self::segments($path), true);
    }

    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function walk(mixed $data, array $segments, ?bool &$found): mixed
    {
        $found = true;

        foreach ($segments as $i => $segment) {
            if ($segment === '*' || $segment === '~') {
                $rest = array_slice($segments, $i + 1);

                if (! is_array($data)) {
                    $found = false;

                    return null;
                }

                $items = match (true) {
                    self::isList($data) => $data,
                    $segment === '~' => array_values($data),
                    default => [$data],
                };

                $out = [];
                foreach ($items as $item) {
                    $value = self::walk($item, $rest, $itemFound);
                    if (! $itemFound) {
                        continue;
                    }
                    // A nested wildcard flattens one level.
                    if (self::isList($value) && (in_array('*', $rest, true) || in_array('~', $rest, true))) {
                        array_push($out, ...$value);
                    } else {
                        $out[] = $value;
                    }
                }

                return $out;
            }

            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];

                continue;
            }

            // A list of one element read without an index: XML "one or many".
            if (is_array($data) && self::isList($data) && count($data) >= 1 && ! ctype_digit($segment) && is_array($data[0]) && array_key_exists($segment, $data[0])) {
                $data = $data[0][$segment];

                continue;
            }

            $found = false;

            return null;
        }

        return $data;
    }

    /**
     * Every path in an item, with a sample value: for the mapping picker.
     *
     * @return array<string, mixed>
     */
    public static function flatten(mixed $data, string $prefix = '', int $depth = 0): array
    {
        if (! is_array($data) || $depth > 8) {
            return $prefix === '' ? [] : [$prefix => $data];
        }

        if ($data === []) {
            return $prefix === '' ? [] : [$prefix => []];
        }

        $out = [];
        if (self::isList($data)) {
            // One entry for the list itself, then the shape of its first item.
            $out[$prefix] = $data;
            foreach (self::flatten($data[0], $prefix === '' ? '*' : $prefix.'.*', $depth + 1) as $key => $value) {
                $out[$key] = $value;
            }

            return $out;
        }

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            foreach (self::flatten($value, $path, $depth + 1) as $k => $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
