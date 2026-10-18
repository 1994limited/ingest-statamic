<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use Statamic\Fields\Field;

/**
 * A group: its own fields mapping ("fields"), read from the item, or from
 * the object at "path" when one is set.
 */
class GroupMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $source = is_array($value) ? $value : $context->item;
        $fields = $field->fieldtype()->fields()->all();

        $mapped = $mapper->map((array) ($mapping['fields'] ?? []), $fields, $source, $context);

        if ($mapped->errors !== []) {
            throw new FieldError(collect($mapped->errors)->map(fn ($m, $h) => "{$h}: {$m}")->implode('; '));
        }

        return array_filter($mapped->values, fn ($v) => $v !== null);
    }
}
