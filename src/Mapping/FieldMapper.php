<?php

namespace NineteenNinetyFour\Ingest\Mapping;

use Statamic\Fields\Field;

/**
 * Turns a feed value into what a fieldtype stores.
 */
interface FieldMapper
{
    /**
     * @param  mixed  $value  the feed value after transforms (never empty)
     * @param  array<string, mixed>  $mapping  the field's mapping settings
     *
     * @throws FieldError when the value can't be used
     */
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed;
}
