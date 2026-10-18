<?php

namespace NineteenNinetyFour\Ingest\Support;

/**
 * Small helpers for feed values.
 */
class Value
{
    /**
     * An XML element read as {"@attr": …, "#text": "…"} becomes its text;
     * lists are unwrapped item by item. Anything else is returned as it is.
     */
    public static function unwrap(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (Path::isList($value)) {
            return array_map(self::unwrap(...), $value);
        }

        if (array_key_exists('#text', $value)) {
            $others = array_filter(array_keys($value), fn ($k) => $k !== '#text' && ! str_starts_with((string) $k, '@'));

            return $others === [] ? $value['#text'] : $value;
        }

        return $value;
    }

    /**
     * A value as text: lists joined, objects as JSON, null as ''.
     */
    public static function text(mixed $value, string $glue = ', '): string
    {
        $value = self::unwrap($value);

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            Path::isList($value) => implode($glue, array_map(fn ($v) => self::text($v, $glue), $value)),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }

    /**
     * A stable hash of any value: arrays with keys are sorted first, so
     * the same data always hashes the same.
     */
    public static function hash(mixed $value): string
    {
        return sha1(json_encode(self::normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            // 12, "12" and 12.0 are the same value to a feed.
            if (is_int($value) || is_float($value)) {
                return (string) (0 + $value);
            }

            return $value;
        }

        if (! Path::isList($value)) {
            ksort($value);
        }

        return array_map(self::normalise(...), $value);
    }

    public static function same(mixed $a, mixed $b): bool
    {
        $empty = fn ($v) => $v === null || $v === '' || $v === [];

        if ($empty($a) && $empty($b)) {
            return true;
        }

        return self::hash($a) === self::hash($b);
    }
}
