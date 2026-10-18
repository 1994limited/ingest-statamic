<?php

namespace NineteenNinetyFour\Ingest\Mapping;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Url;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Modifiers\Modify;
use Throwable;

/**
 * Transforms change a feed value before it's saved. A mapping lists them in
 * order:
 *
 *   transforms: [trim, { name: replace, args: [' (PB)', ''] }, { name: math, args: ['*', 1.2] }]
 *
 * Text transforms apply to each value of a list. Add your own with
 * Ingest::transform('name', fn ($value, array $args, Context $context) => …).
 * Statamic's own modifiers can be used too ({ name: modifier, args: [name, …] })
 * from an allow-list.
 */
class Transforms
{
    /** Transforms that take the whole value, list or not. */
    private const WHOLE = ['split', 'join', 'first', 'last', 'nth', 'pluck', 'unique', 'filter_empty', 'count', 'json_decode', 'default', 'map', 'sort', 'reverse'];

    /** Statamic modifiers that are safe on feed data (no templates, no I/O). */
    public const MODIFIERS = [
        'ascii', 'camelize', 'dashify', 'deslugify', 'ensure_left', 'ensure_right', 'entities', 'excerpt', 'kebab', 'lcfirst',
        'ltrim', 'rtrim', 'safe_truncate', 'sentence_list', 'singular', 'plural', 'slugify', 'snake', 'squish', 'studly',
        'swap_case', 'title', 'trim', 'ucfirst', 'upper', 'lower', 'widont', 'word_count', 'wrap', 'nl2br', 'smartypants',
        'strip_tags', 'sanitize', 'decode', 'urlencode', 'urldecode', 'rawurlencode', 'mod', 'ceil', 'floor', 'format_number',
        'replace', 'regex_replace', 'remove_left', 'remove_right', 'truncate', 'limit', 'offset', 'flatten',
    ];

    /** @var array<string, callable(mixed, array, Context): mixed> */
    private array $custom = [];

    public function register(string $name, callable $transform): void
    {
        $this->custom[$name] = $transform;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_merge($this->builtIn(), array_keys($this->custom));
    }

    /**
     * Apply a list of transforms in order.
     */
    public function applyAll(mixed $value, array $transforms, Context $context): mixed
    {
        foreach ($transforms as $transform) {
            [$name, $args] = self::parse($transform);
            if ($name === '') {
                continue;
            }
            $value = $this->apply($name, $value, $args, $context);
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: array}
     */
    public static function parse(mixed $transform): array
    {
        if (is_string($transform)) {
            return [trim($transform), []];
        }

        if (is_array($transform)) {
            return [trim((string) ($transform['name'] ?? '')), array_values((array) ($transform['args'] ?? []))];
        }

        return ['', []];
    }

    public function apply(string $name, mixed $value, array $args, Context $context): mixed
    {
        $value = Value::unwrap($value);

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($value, $args, $context);
        }

        if (! in_array($name, $this->builtIn(), true)) {
            throw new FieldError("Unknown transform [{$name}].");
        }

        if (Path::isList($value) && ! in_array($name, self::WHOLE, true)) {
            return array_map(fn ($v) => $this->one($name, $v, $args, $context), $value);
        }

        return $this->one($name, $value, $args, $context);
    }

    /** @return string[] */
    public function builtIn(): array
    {
        return [
            'trim', 'lower', 'upper', 'title', 'ucfirst', 'slugify', 'strip_tags', 'decode_entities', 'text', 'sanitize_html',
            'html_to_markdown', 'markdown_to_html', 'replace', 'regex_replace', 'prepend', 'append', 'truncate', 'split', 'join',
            'first', 'last', 'nth', 'pluck', 'unique', 'filter_empty', 'count', 'sort', 'reverse', 'default', 'map', 'math', 'round',
            'number', 'money', 'boolean', 'date', 'json_decode', 'absolute_url', 'modifier',
        ];
    }

    private function one(string $name, mixed $value, array $args, Context $context): mixed
    {
        $text = fn () => Value::text($value);
        $arg = fn (int $i, mixed $default = null) => $args[$i] ?? $default;

        return match ($name) {
            'trim' => is_string($value) ? trim($value, $arg(0) !== null ? (string) $arg(0) : " \t\n\r\0\x0B\u{00A0}") : $value,
            'lower' => mb_strtolower($text()),
            'upper' => mb_strtoupper($text()),
            'title' => Str::title($text()),
            'ucfirst' => Str::ucfirst($text()),
            'slugify' => Str::slug($text()),
            'strip_tags' => strip_tags($text()),
            'decode_entities' => html_entity_decode($text(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'text' => Html::text($text()),
            'sanitize_html' => Html::sanitize($text()),
            'html_to_markdown' => Html::toMarkdown($text()),
            'markdown_to_html' => Html::fromMarkdown($text()),
            'replace' => str_replace((string) $arg(0, ''), (string) $arg(1, ''), $text()),
            'regex_replace' => $this->regexReplace($text(), (string) $arg(0, ''), (string) $arg(1, '')),
            'prepend' => Value::isEmpty($value) ? $value : $arg(0, '').$text(),
            'append' => Value::isEmpty($value) ? $value : $text().$arg(0, ''),
            'truncate' => Str::limit($text(), (int) $arg(0, 100), (string) $arg(1, '…')),
            'split' => Value::isEmpty($value) ? [] : (Path::isList($value) ? $value : array_values(array_filter(array_map('trim', explode((string) $arg(0, ','), $text())), fn ($v) => $v !== ''))),
            'join' => Path::isList($value) ? implode((string) $arg(0, ', '), array_map(fn ($v) => Value::text($v), $value)) : $value,
            'first' => Path::isList($value) ? ($value[0] ?? null) : $value,
            'last' => Path::isList($value) ? ($value[count($value) - 1] ?? null) : $value,
            'nth' => Path::isList($value) ? ($value[(int) $arg(0, 0)] ?? null) : ((int) $arg(0, 0) === 0 ? $value : null),
            'pluck' => Path::isList($value) ? array_map(fn ($v) => Value::unwrap(Path::get($v, (string) $arg(0, ''))), $value) : Value::unwrap(Path::get($value, (string) $arg(0, ''))),
            'unique' => Path::isList($value) ? array_values(array_unique(array_map(fn ($v) => is_scalar($v) ? $v : json_encode($v), $value))) : $value,
            'filter_empty' => Path::isList($value) ? array_values(array_filter($value, fn ($v) => ! Value::isEmpty($v))) : $value,
            'count' => Path::isList($value) ? count($value) : (Value::isEmpty($value) ? 0 : 1),
            'sort' => Path::isList($value) ? collect($value)->sort()->values()->all() : $value,
            'reverse' => Path::isList($value) ? array_reverse($value) : $value,
            'default' => Value::isEmpty($value) ? $arg(0) : $value,
            'map' => $this->map($value, (array) $arg(0, []), $arg(1)),
            'math' => $this->math($value, (string) $arg(0, '*'), $arg(1, 1)),
            'round' => Value::isEmpty($value) ? $value : round((float) self::number($value), (int) $arg(0, 0)),
            'number' => Value::isEmpty($value) ? null : self::number($value),
            'money' => Value::isEmpty($value) ? null : (int) round(self::number($value) * (10 ** (int) $arg(0, 2))),
            'boolean' => Value::isEmpty($value) ? null : self::boolean($value, (array) $arg(0, []), (array) $arg(1, [])),
            'date' => $this->date($value, $arg(0), $arg(1), (string) $arg(2, 'c')),
            'json_decode' => is_string($value) ? json_decode($value, true) : $value,
            'absolute_url' => Value::isEmpty($value) ? $value : Url::resolve((string) ($arg(0) ?: $context->baseUrl ?: ''), $text()),
            'modifier' => $this->modifier($value, (string) $arg(0, ''), array_slice($args, 1)),
        };
    }

    private function regexReplace(string $value, string $pattern, string $replacement): string
    {
        if ($pattern === '') {
            return $value;
        }

        // Accept a bare pattern or one with delimiters.
        if (@preg_match($pattern, '') === false) {
            $pattern = '/'.str_replace('/', '\/', $pattern).'/u';
        }

        if (@preg_match($pattern, '') === false) {
            throw new FieldError("The pattern {$pattern} isn't a valid regular expression.");
        }

        $result = preg_replace($pattern, $replacement, $value);

        if ($result === null) {
            throw new FieldError("The pattern {$pattern} couldn't be applied (too complex for this value).");
        }

        return $result;
    }

    private function map(mixed $value, array $table, mixed $fallback): mixed
    {
        // Saved from the Control Panel as rows of {from, to}.
        if (Path::isList($table)) {
            $table = collect($table)->filter(fn ($row) => is_array($row) && array_key_exists('from', $row))->mapWithKeys(fn ($row) => [(string) $row['from'] => $row['to'] ?? null])->all();
        }

        $lookup = fn ($v) => array_key_exists(Value::text($v), $table)
            ? $table[Value::text($v)]
            : (array_key_exists(mb_strtolower(Value::text($v)), array_change_key_case($table)) ? array_change_key_case($table)[mb_strtolower(Value::text($v))] : ($fallback === '*' ? $v : $fallback));

        return Path::isList($value) ? array_map($lookup, $value) : $lookup($value);
    }

    private function math(mixed $value, string $operator, mixed $operand): mixed
    {
        if (Value::isEmpty($value)) {
            return $value;
        }

        // "* 1.2" in one argument.
        if (preg_match('/^\s*([+\-*\/])\s*(-?\d+(?:\.\d+)?)\s*$/', $operator, $m)) {
            [$operator, $operand] = [$m[1], $m[2]];
        }

        $a = self::number($value);
        $b = (float) $operand;

        return match ($operator) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b == 0 ? throw new FieldError('Can\'t divide by zero.') : $a / $b,
            default => throw new FieldError("Unknown maths operator [{$operator}]."),
        };
    }

    /**
     * A number from text like "£1,234.50", "1.234,50", "12,99" or "1 234".
     */
    public static function number(mixed $value): float|int
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = preg_replace('/[^\d,.\-]/', '', Value::text($value));

        if ($text === '' || $text === '-' || $text === null) {
            throw new FieldError('"'.Value::text($value).'" isn\'t a number.');
        }

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');

        if ($comma !== false && $dot !== false) {
            // The last separator is the decimal one.
            $text = $comma > $dot ? str_replace(['.', ','], ['', '.'], $text) : str_replace(',', '', $text);
        } elseif ($comma !== false) {
            // "12,99" is a decimal; "1,234" and "1,234,567" are thousands.
            $text = preg_match('/^-?\d{1,3}(,\d{3})+$/', $text) ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif ($dot !== false && substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text);
        }

        if (! is_numeric($text)) {
            throw new FieldError('"'.Value::text($value).'" isn\'t a number.');
        }

        return str_contains($text, '.') ? (float) $text : (int) $text;
    }

    public static function boolean(mixed $value, array $true = [], array $false = []): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = mb_strtolower(trim(Value::text($value)));
        $true = array_map(fn ($v) => mb_strtolower((string) $v), $true ?: ['1', 'true', 'yes', 'y', 'on', 'live', 'published', 'active', 'enabled', 'x', '✓']);
        $false = array_map(fn ($v) => mb_strtolower((string) $v), $false ?: ['0', 'false', 'no', 'n', 'off', 'draft', 'inactive', 'disabled', '']);

        if (in_array($text, $true, true)) {
            return true;
        }
        if (in_array($text, $false, true)) {
            return false;
        }

        throw new FieldError("\"{$text}\" isn't a yes or no value. Add it to the transform's true or false list.");
    }

    /**
     * Parse a date (any common format, a Unix timestamp in seconds or
     * milliseconds, or the given format) in a timezone, and format it.
     */
    private function date(mixed $value, mixed $format, mixed $timezone, string $output): ?string
    {
        if (Value::isEmpty($value)) {
            return null;
        }

        $date = self::parseDate(Value::text($value), $format ? (string) $format : null, $timezone ? (string) $timezone : null);

        return $output === 'c' ? $date->toIso8601String() : $date->format($output);
    }

    public static function parseDate(string $text, ?string $format = null, ?string $timezone = null): CarbonImmutable
    {
        $text = trim($text);
        $timezone ??= config('app.timezone', 'UTC');

        try {
            if ($format) {
                $date = CarbonImmutable::createFromFormat($format, $text, $timezone);
                if ($date === false) {
                    throw new FieldError("\"{$text}\" doesn't match the date format {$format}.");
                }

                return $date;
            }

            if (preg_match('/^\d{13}$/', $text)) {
                return CarbonImmutable::createFromTimestampMs((int) $text, $timezone);
            }
            if (preg_match('/^\d{9,10}$/', $text)) {
                return CarbonImmutable::createFromTimestamp((int) $text, $timezone);
            }
            // UK order is the default for ambiguous day/month: 03/04/2026 is 3 April.
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})(.*)$#', $text, $m)) {
                return CarbonImmutable::parse(sprintf('%04d-%02d-%02d%s', $m[3], $m[2], $m[1], $m[4]), $timezone);
            }

            return CarbonImmutable::parse($text, $timezone);
        } catch (FieldError $e) {
            throw $e;
        } catch (Throwable) {
            throw new FieldError("\"{$text}\" isn't a date Ingest can read. Set the date format.");
        }
    }

    private function modifier(mixed $value, string $name, array $args): mixed
    {
        $name = Str::snake($name);

        if (! in_array($name, self::MODIFIERS, true)) {
            throw new FieldError("The {$name} modifier can't be used on feed data.");
        }

        return Modify::value($value)->context([])->{$name}($args)->fetch();
    }
}
