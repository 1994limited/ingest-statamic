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
 * The table fieldtype: a list of rows, each a list (or object) of cells.
 */
class TableMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        if (! Path::isList($value)) {
            return null;
        }

        return array_map(fn ($row) => ['cells' => array_map(
            fn ($cell) => Html::text(Value::text($cell)),
            array_values(is_array($row) ? $row : [$row])
        )], $value);
    }
}
