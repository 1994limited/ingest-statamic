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
 * A list of text values: from a list in the feed, or text split on the
 * mapping's "delimiter" (default "|", then ",").
 */
class ListMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        if (! Path::isList($value)) {
            $text = Value::text($value);
            $delimiter = $mapping['delimiter'] ?? (str_contains($text, '|') ? '|' : ',');
            $value = explode($delimiter, $text);
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => Html::text(Value::text($v)), $value), fn ($v) => $v !== '')));
    }
}
