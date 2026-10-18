<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Grid and Replicator rows: each item of the list at "path" becomes a row,
 * its own fields mapped with "fields" (paths inside a row are relative to
 * the row; "^.path" reads the top-level item).
 *
 *   mode: replace   the feed's rows replace the field's rows (default)
 *         append    the feed's rows are added after the existing ones
 *         merge     rows are matched on "merge_key" (a row field): matched
 *                   rows are updated, new ones added, others kept
 */
abstract class RowsMapper implements FieldMapper
{
    /** The fields a row has, and any extra values it needs (a set's type). */
    abstract protected function row(Field $field, array $element, array $mapping, Context $context, Mapper $mapper): ?array;

    abstract protected function rowFields(Field $field, ?string $set = null): Fields;

    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $elements = Path::isList($value) ? $value : [$value];
        $mode = $mapping['mode'] ?? 'replace';
        $mergeKey = $mapping['merge_key'] ?? null;

        if ($mode === 'merge' && ! $mergeKey) {
            throw new FieldError('Merging rows needs a merge key: the row field that identifies a row.');
        }

        $rows = [];
        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                $element = ['value' => $element];
            }

            $row = $this->row($field, $element, $mapping, $context, $mapper);
            if ($row === null) {
                continue;
            }

            $identity = $mergeKey ? Value::text($row[$mergeKey] ?? '') : (string) $index;
            $row = ['id' => substr(sha1($field->handle().'|'.$identity), 0, 12)] + $row;
            $rows[] = $row;
        }

        $existing = array_values(array_filter((array) ($context->current[$field->handle()] ?? []), 'is_array'));

        return match ($mode) {
            'append' => array_merge($existing, array_values(array_filter($rows, fn ($r) => ! in_array($r['id'], array_column($existing, 'id'), true)))),
            'merge' => $this->merge($existing, $rows, $mergeKey),
            default => $rows,
        };
    }

    private function merge(array $existing, array $rows, string $key): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[Value::text($row[$key] ?? '')] = $row;
        }

        $out = [];
        foreach ($existing as $row) {
            $k = Value::text($row[$key] ?? '');
            if (isset($byKey[$k])) {
                // Keep the existing row's id and any fields the feed doesn't map.
                $out[] = array_merge($row, array_diff_key($byKey[$k], ['id' => true]));
                unset($byKey[$k]);
            } else {
                $out[] = $row;
            }
        }

        return array_merge($out, array_values($byKey));
    }

    protected function mapRow(Fields $fields, array $element, array $mapping, Context $context, Mapper $mapper, string $label): array
    {
        $mapped = $mapper->map((array) ($mapping['fields'] ?? []), $fields->all(), $element, $context);

        foreach ($mapped->errors as $handle => $message) {
            $context->warn("{$label} › {$handle}: {$message}");
        }

        return array_filter($mapped->values, fn ($v) => $v !== null);
    }
}
