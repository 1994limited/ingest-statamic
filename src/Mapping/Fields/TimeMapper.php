<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

class TimeMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $text = trim(Value::text($value));
        $time = strtotime('1970-01-01 '.$text.' UTC');

        if ($time === false) {
            throw new FieldError("\"{$text}\" isn't a time.");
        }

        return gmdate($field->get('seconds_enabled') ? 'H:i:s' : 'H:i', $time);
    }
}
