<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Dictionary fields (countries, currencies…) store the item's key: the
 * feed's value is matched against keys, then labels.
 */
class DictionaryMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $dictionary = $field->fieldtype()->dictionary();
        $options = $dictionary->options();
        $values = Path::isList($value) ? $value : [$value];
        $out = [];

        foreach ($values as $one) {
            $text = mb_strtolower(trim(Value::text($one)));
            $key = collect($options)->keys()->first(fn ($k) => mb_strtolower((string) $k) === $text)
                ?? collect($options)->search(fn ($label) => mb_strtolower(trim(strip_tags((string) $label))) === $text);

            if ($key === null || $key === false) {
                $context->warn("{$field->display()}: \"{$text}\" isn't in the dictionary, so it was left out.");

                continue;
            }

            $out[] = (string) $key;
        }

        return $field->get('max_items') === 1 ? ($out[0] ?? null) : $out;
    }
}
