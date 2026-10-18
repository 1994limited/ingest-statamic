<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use Statamic\Fields\Field;

/**
 * Yes or no. Understands true/false, 1/0, yes/no, on/off and more; add
 * your own words with "true_values" and "false_values".
 */
class ToggleMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        return Transforms::boolean($value, (array) ($mapping['true_values'] ?? []), (array) ($mapping['false_values'] ?? []));
    }
}
