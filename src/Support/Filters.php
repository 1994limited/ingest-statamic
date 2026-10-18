<?php

namespace NineteenNinetyFour\Ingest\Support;

use NineteenNinetyFour\Ingest\IngestException;

/**
 * A feed's filters: rules every item must pass to be imported. Items
 * that don't pass are left out, so they count as missing from the feed.
 *
 *   { path: status, op: equals, value: live }
 *
 * Operators: equals, not_equals, contains, not_contains, starts_with, in,
 * not_in (value: a list or comma-separated text), empty, not_empty, gt,
 * gte, lt, lte, matches (a regular expression).
 */
class Filters
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'in', 'not_in', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte', 'matches'];

    /**
     * @param  array<int, array{path?: string, op?: string, value?: mixed}>  $filters
     */
    public static function passes(array $item, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (! self::test($item, $filter)) {
                return false;
            }
        }

        return true;
    }

    public static function test(array $item, array $filter): bool
    {
        $path = (string) ($filter['path'] ?? '');
        if ($path === '') {
            return true;
        }

        $raw = Value::unwrap(Path::get($item, $path));
        $values = Path::isList($raw) ? $raw : [$raw];
        $op = (string) ($filter['op'] ?? 'equals');
        $expected = $filter['value'] ?? null;

        // With a list, a positive rule passes when any value matches; a
        // negative rule passes only when none do.
        $negative = in_array($op, ['not_equals', 'not_contains', 'not_in'], true);
        $positive = ['not_equals' => 'equals', 'not_contains' => 'contains', 'not_in' => 'in'][$op] ?? $op;

        if (in_array($op, ['empty', 'not_empty'], true)) {
            $empty = collect($values)->every(fn ($v) => Value::isEmpty($v));

            return $op === 'empty' ? $empty : ! $empty;
        }

        $any = collect($values)->contains(fn ($v) => self::compare(Value::text($v), $positive, $expected));

        return $negative ? ! $any : $any;
    }

    private static function compare(string $value, string $op, mixed $expected): bool
    {
        $text = mb_strtolower(trim($value));
        $list = fn () => array_map(fn ($v) => mb_strtolower(trim((string) $v)), is_array($expected) ? $expected : explode(',', (string) $expected));
        $expectedText = mb_strtolower(trim(Value::text($expected)));

        return match ($op) {
            'equals' => $text === $expectedText,
            'contains' => $expectedText !== '' && str_contains($text, $expectedText),
            'starts_with' => $expectedText !== '' && str_starts_with($text, $expectedText),
            'in' => in_array($text, $list(), true),
            'gt', 'gte', 'lt', 'lte' => is_numeric($value) && is_numeric($expected) && match ($op) {
                'gt' => (float) $value > (float) $expected,
                'gte' => (float) $value >= (float) $expected,
                'lt' => (float) $value < (float) $expected,
                'lte' => (float) $value <= (float) $expected,
            },
            'matches' => self::matches($value, (string) $expected),
            default => throw new IngestException("Unknown filter operator [{$op}]."),
        };
    }

    private static function matches(string $value, string $pattern): bool
    {
        if (@preg_match($pattern, '') === false) {
            $pattern = '/'.str_replace('/', '\/', $pattern).'/iu';
        }

        $result = @preg_match($pattern, $value);

        if ($result === false) {
            throw new IngestException("The filter pattern {$pattern} isn't a valid regular expression.");
        }

        return $result === 1;
    }
}
