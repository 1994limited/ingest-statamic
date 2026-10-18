<?php

namespace NineteenNinetyFour\Ingest\Http;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Mapping\FieldMappers;
use Statamic\Fields\Field;
use Throwable;

/**
 * Describes a destination's fields for the mapping screen: what each is,
 * the options a select has, and the fields inside grids, groups,
 * replicator sets and Cargo variants.
 */
class FieldSchema
{
    public function __construct(private FieldMappers $mappers) {}

    /**
     * @param  Collection<string, Field>  $fields
     */
    public function describe(Collection $fields, int $depth = 0): array
    {
        return $fields
            ->reject(fn (Field $f) => in_array($f->type(), ['section', 'spacer', 'html', 'revealer'], true))
            ->map(fn (Field $field) => $this->field($field, $depth))
            ->values()
            ->all();
    }

    private function field(Field $field, int $depth): array
    {
        $type = $field->type();
        $out = [
            'handle' => $field->handle(),
            'display' => $field->display() ?: $field->handle(),
            'type' => $type,
            'kind' => $this->kind($type),
            'required' => (bool) $field->isRequired(),
            'supported' => $this->mappers->supports($type),
            'instructions' => $field->instructions(),
        ];

        if (in_array($type, ['select', 'radio', 'button_group', 'checkboxes'], true)) {
            $out['options'] = collect((array) $field->get('options', []))->map(fn ($label, $key) => is_array($label)
                ? ['value' => (string) ($label['key'] ?? ''), 'label' => (string) ($label['value'] ?? $label['key'] ?? '')]
                : ['value' => (string) (is_int($key) ? $label : $key), 'label' => (string) ($label ?? $key)])->values()->all();
            $out['multiple'] = $type === 'checkboxes' || (bool) $field->get('multiple');
        }

        if (in_array($type, ['entries', 'terms', 'users'], true)) {
            $out['collections'] = (array) $field->get('collections', []);
            $out['taxonomies'] = (array) $field->get('taxonomies', []);
            $out['max_items'] = $field->get('max_items');
        }

        if ($type === 'assets') {
            $out['container'] = $field->get('container');
            $out['max_files'] = $field->get('max_files');
        }

        if ($depth > 1) {
            return $out;
        }

        try {
            if (in_array($type, ['grid', 'group'], true)) {
                $out['fields'] = $this->describe($field->fieldtype()->fields()->all(), $depth + 1);
            }

            if ($type === 'replicator') {
                $out['sets'] = collect($field->fieldtype()->flattenedSetsConfig())->map(fn ($set, $handle) => [
                    'handle' => $handle,
                    'display' => $set['display'] ?? $handle,
                    'fields' => $this->describe($field->fieldtype()->fields($handle)->all(), $depth + 1),
                ])->values()->all();
            }

            if ($type === 'product_variants') {
                $out['fields'] = $this->describe($field->fieldtype()->optionFields()->all()
                    ->reject(fn ($f) => in_array($f->handle(), ['key', 'variant', 'price', 'stock'], true)), $depth + 1);
            }
        } catch (Throwable) {
            // A fieldtype that can't describe its fields: map it as a whole.
        }

        return $out;
    }

    /**
     * Which mapping editor the field gets.
     */
    private function kind(string $type): string
    {
        return match ($type) {
            'grid', 'replicator' => 'rows',
            'group' => 'group',
            'product_variants' => 'variants',
            'entries', 'terms', 'users' => 'relationship',
            'assets' => 'assets',
            'select', 'radio', 'button_group', 'checkboxes' => 'options',
            'toggle' => 'toggle',
            'date' => 'date',
            default => 'value',
        };
    }
}
