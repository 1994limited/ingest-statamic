<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use Statamic\Fields\Field;

/**
 * Numbers, from text like "1,234.50" or "12,99".
 */
class NumberMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $number = Transforms::number(is_array($value) ? ($value[0] ?? null) : $value);

        return $field->type() === 'float' ? (float) $number : (int) round($number);
    }
}
