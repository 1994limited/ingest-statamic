<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

class GridMapper extends RowsMapper
{
    protected function row(Field $field, array $element, array $mapping, Context $context, Mapper $mapper): ?array
    {
        return $this->mapRow($this->rowFields($field), $element, $mapping, $context, $mapper, $field->display());
    }

    protected function rowFields(Field $field, ?string $set = null): Fields
    {
        return $field->fieldtype()->fields();
    }
}
