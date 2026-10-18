<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Fieldtypes Ingest has no mapper for: the value is saved as it came,
 * with text values stripped of HTML.
 */
class PassthroughMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $value = Value::unwrap($value);

        return is_string($value) && ($mapping['html'] ?? 'strip') === 'strip' ? Html::text($value) : $value;
    }
}
