<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Markdown. HTML from the feed is converted; Markdown is passed through
 * HTML and back so any HTML inside it is sanitised (markdown can carry raw
 * HTML that would reach the page). "html: trusted" skips that.
 */
class MarkdownMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $text = Value::text($value, "\n\n");

        if (($mapping['html'] ?? null) === 'trusted') {
            return $text;
        }

        if (Html::looksLikeHtml($text)) {
            // Markdown with a little HTML in it, or HTML outright: both end up as safe Markdown.
            return Html::toMarkdown(str_contains($text, '<p') || str_contains($text, '<div') ? $text : Html::fromMarkdown($text));
        }

        return $text;
    }
}
