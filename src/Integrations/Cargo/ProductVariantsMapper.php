<?php

namespace NineteenNinetyFour\Ingest\Integrations\Cargo;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Cargo's product variants, built from a repeating part of the feed: each
 * element becomes one option (Hardback, Paperback; Red / Small…).
 *
 *   path: formats.*
 *   dimensions:                       the variant names and where their values are
 *     - { name: Format, path: binding }
 *   sku: sku                          stored on the option; options are matched on it
 *   price: { path: price }            "12.99" → 1299 (minor_units: true if already pence)
 *   stock: { path: stock, mode: set } set (default) or lower_only
 *   create_only on price or stock: set it for new options only (a stock
 *                feed keeps it up to date)
 *   fields: { …option field: mapping }
 *   mode: replace (default) or merge (keep options the feed doesn't list)
 *
 * Option keys are Cargo's: the dimension values joined with "_".
 */
class ProductVariantsMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $elements = array_values(array_filter(Path::isList($value) ? $value : [$value], 'is_array'));
        $dimensions = array_values(array_filter((array) ($mapping['dimensions'] ?? []), fn ($d) => is_array($d) && ! empty($d['name']) && ! empty($d['path'])));

        if ($dimensions === []) {
            throw new FieldError('Variants need at least one dimension: a name (Format) and where its value is in the feed.');
        }

        $current = (array) ($context->current[$field->handle()] ?? []);
        $currentOptions = collect((array) ($current['options'] ?? []))->keyBy(fn ($o) => (string) ($o['sku'] ?? $o['key'] ?? ''));
        $optionFields = $field->fieldtype()->optionFields()->all();

        $variants = array_map(fn ($d) => ['name' => (string) $d['name'], 'values' => []], $dimensions);
        $options = [];

        foreach ($elements as $element) {
            $values = [];
            foreach ($dimensions as $i => $dimension) {
                $v = Html::text(Value::text(Path::get($element, $dimension['path'])));
                if ($v === '') {
                    $context->warn("Variants: an option has no {$dimension['name']}, so it was left out.");

                    continue 2;
                }
                $values[] = $v;
                if (! in_array($v, $variants[$i]['values'], true)) {
                    $variants[$i]['values'][] = $v;
                }
            }

            $key = implode('_', $values);
            $option = ['key' => $key, 'variant' => implode(', ', $values)];

            if (! empty($mapping['sku'])) {
                $option['sku'] = Value::text(Path::get($element, $mapping['sku']));
            }

            $previous = $currentOptions->get($option['sku'] ?? $key) ?? $currentOptions->get($key);

            if (isset($mapping['price']) && ! (! empty($mapping['price']['create_only']) && $previous)) {
                $price = $mapper->resolve((array) $mapping['price'], $element, $context);
                if (! Value::isEmpty($price)) {
                    $option['price'] = MoneyMapper::minor($price, ! empty($mapping['price']['minor_units']));
                }
            }

            if (isset($mapping['stock']) && ! (! empty($mapping['stock']['create_only']) && $previous)) {
                $stock = $mapper->resolve((array) $mapping['stock'], $element, $context);
                if (! Value::isEmpty($stock)) {
                    $stock = (int) $stock;
                    if (($mapping['stock']['mode'] ?? 'set') === 'lower_only' && isset($previous['stock'])) {
                        $stock = min($stock, (int) $previous['stock']);
                    }
                    $option['stock'] = $stock;
                }
            }

            if (! empty($mapping['fields'])) {
                $extra = $mapper->map((array) $mapping['fields'], $optionFields, $element, $context);
                foreach ($extra->errors as $handle => $message) {
                    $context->warn("Variants › {$handle}: {$message}");
                }
                $option += array_filter($extra->values, fn ($v) => $v !== null);
            }

            // Keep option fields the feed doesn't map.
            $options[$key] = $previous ? array_merge($previous, $option) : $option;
        }

        if (($mapping['mode'] ?? 'replace') === 'merge') {
            foreach ((array) ($current['options'] ?? []) as $old) {
                $options[(string) $old['key']] ??= $old;
            }
            foreach ((array) ($current['variants'] ?? []) as $i => $old) {
                foreach ((array) ($old['values'] ?? []) as $v) {
                    if (isset($variants[$i]) && ! in_array($v, $variants[$i]['values'], true)) {
                        $variants[$i]['values'][] = $v;
                    }
                }
            }
        }

        if ($options === []) {
            return null;
        }

        return ['variants' => $variants, 'options' => array_values($options)];
    }
}
