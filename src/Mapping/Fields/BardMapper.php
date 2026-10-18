<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;

/**
 * Bard, from HTML (sanitised) or plain text (paragraphs on blank lines).
 * The blueprint's Bard settings are never changed. "markdown: true" reads
 * the feed value as Markdown first.
 */
class BardMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        // Already ProseMirror (from another Statamic site, say).
        if (is_array($value) && ! Path::isList($value) && ($value['type'] ?? null) === 'doc') {
            $value = $value['content'] ?? [];
        }
        if (Path::isList($value) && isset($value[0]['type'])) {
            if (($mapping['html'] ?? null) === 'trusted') {
                return $value;
            }

            // Untrusted structure goes through HTML and the sanitiser like anything else.
            $value = (new Augmentor($field->fieldtype()))->renderProsemirrorToHtml(['type' => 'doc', 'content' => $value]);
        }

        $text = Value::text($value, "\n\n");

        if (! empty($mapping['markdown'])) {
            $html = Html::fromMarkdown($text);
        } elseif (Html::looksLikeHtml($text)) {
            $html = ($mapping['html'] ?? null) === 'trusted' ? $text : Html::sanitize($text);
        } else {
            $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
            $html = implode('', array_map(fn ($p) => '<p>'.nl2br(e(trim($p)), false).'</p>', $paragraphs));
        }

        if ($html === '') {
            return null;
        }

        if ($field->get('save_html')) {
            return $html;
        }

        $doc = (new Augmentor($field->fieldtype()))->renderHtmlToProsemirror($html);

        return $doc['content'] ?? [];
    }
}
