<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * The array fieldtype: keys and text values. With fixed "keys" in the
 * field's config, only those are kept.
 */
class ArrayMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        if (! is_array($value) || Path::isList($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $key => $v) {
            $out[(string) $key] = Html::text(Value::text($v));
        }

        $keys = $field->get('keys');
        if (is_array($keys) && $keys !== []) {
            $allowed = array_map(fn ($k, $v) => is_array($v) ? ($v['key'] ?? $k) : $k, array_keys($keys), $keys);
            $out = array_intersect_key($out, array_flip(array_map('strval', $allowed)));
        }

        return $out;
    }
}
