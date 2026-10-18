<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

class SlugMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        return Str::slug(Value::text($value), $field->get('separator', '-'));
    }
}
