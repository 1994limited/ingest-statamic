<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Select, radio, button group and checkboxes. A feed value matches an
 * option's key or label (any case), or a row of the mapping's "values"
 * table ({from, to}). Unknown values: "unknown: skip" (default, with a
 * warning), "raw" (save as is, when the field allows any value) or "error".
 */
class OptionsMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $multiple = $field->type() === 'checkboxes' || ($field->type() === 'select' && $field->get('multiple'));
        $values = Path::isList($value) ? $value : [$value];

        $out = [];
        foreach ($values as $one) {
            $match = $this->match(Value::text($one), $field, $mapping);

            if ($match === null) {
                $unknown = $mapping['unknown'] ?? 'skip';
                $text = Value::text($one);

                if ($unknown === 'raw' || ($field->type() === 'select' && $field->get('taggable'))) {
                    $out[] = $text;
                } elseif ($unknown === 'error') {
                    throw new FieldError("\"{$text}\" isn't one of the options.");
                } else {
                    $context->warn("{$field->display()}: \"{$text}\" isn't one of the options, so it was left out.");
                }

                continue;
            }

            $out[] = $match;
        }

        $out = array_values(array_unique($out));

        return $multiple ? $out : ($out[0] ?? null);
    }

    private function match(string $value, Field $field, array $mapping): ?string
    {
        $needle = mb_strtolower(trim($value));

        foreach ((array) ($mapping['values'] ?? []) as $from => $to) {
            if (is_array($to)) {
                [$from, $to] = [$to['from'] ?? null, $to['to'] ?? null];
            }
            if ($from !== null && mb_strtolower(trim((string) $from)) === $needle) {
                return (string) $to;
            }
        }

        foreach ($this->options($field) as $key => $label) {
            if (mb_strtolower((string) $key) === $needle || mb_strtolower((string) $label) === $needle) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function options(Field $field): array
    {
        $options = (array) $field->get('options', []);
        $out = [];

        foreach ($options as $key => $label) {
            // Statamic 6 saves options as a list of {key, value}.
            if (is_array($label)) {
                $out[(string) ($label['key'] ?? '')] = (string) ($label['value'] ?? $label['key'] ?? '');
            } elseif (is_int($key) && array_is_list($options)) {
                $out[(string) $label] = (string) $label;
            } else {
                $out[(string) $key] = (string) ($label ?? $key);
            }
        }

        return $out;
    }
}
