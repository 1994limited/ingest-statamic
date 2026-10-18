<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Dates, read in the mapping's "timezone" (default: the app's) with an
 * optional "format", and stored in UTC as Statamic does. Range fields read
 * the end from "end_path".
 */
class DateMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $format = $field->get('format') ?: ($field->get('time_enabled') ? 'Y-m-d H:i' : 'Y-m-d');
        $parse = fn ($v) => Transforms::parseDate(Value::text(is_array($v) ? ($v[0] ?? null) : $v), $mapping['format'] ?? null, $mapping['timezone'] ?? null);

        $start = $parse($value);
        $out = $field->get('time_enabled') ? $start->utc()->format($format) : $start->format($format);

        if ($field->get('mode') === 'range') {
            $end = isset($mapping['end_path']) ? $mapper->read($mapping['end_path'], $context->item, $context) : null;
            $end = Value::isEmpty($end) ? $start : $parse($end);

            return ['start' => $out, 'end' => $field->get('time_enabled') ? $end->utc()->format($format) : $end->format($format)];
        }

        return $out;
    }
}
