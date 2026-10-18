<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Plain text. Tags are removed and entities decoded, because Antlers
 * prints {{ title }} as it is: HTML from a feed must not reach the page.
 * "html: sanitize" keeps safe HTML instead, for fields shown with HTML.
 */
class TextMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $text = Value::text($value);

        return match ($mapping['html'] ?? 'strip') {
            'sanitize' => Html::sanitize($text),
            'trusted' => $text,
            default => $field->type() === 'textarea'
                ? trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : Html::text($text),
        };
    }
}
