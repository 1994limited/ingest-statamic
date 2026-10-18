<?php

namespace NineteenNinetyFour\Ingest\Support;

use League\HTMLToMarkdown\HtmlConverter;
use Statamic\Facades\Markdown;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML from feeds is untrusted: rich text is sanitised (an allow-list of
 * safe tags and attributes; no scripts, iframes, event handlers or
 * javascript: links) and plain text has its tags removed.
 */
class Html
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowMediaSchemes(['http', 'https'])
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->withMaxInputLength(5_000_000)
        );

        return trim(self::$sanitizer->sanitize($html));
    }

    /**
     * Plain text: tags removed, entities decoded, whitespace tidied.
     */
    public static function text(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Keep paragraph and line breaks as spaces between words.
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text));
    }

    public static function looksLikeHtml(?string $value): bool
    {
        return is_string($value) && preg_match('/<\/?[a-z][a-z0-9]*[\s>\/]/i', $value) === 1;
    }

    public static function toMarkdown(?string $html): string
    {
        $html = self::sanitize($html);

        if ($html === '') {
            return '';
        }

        return trim((new HtmlConverter(['strip_tags' => true, 'header_style' => 'atx', 'hard_break' => true]))->convert($html));
    }

    public static function fromMarkdown(?string $markdown): string
    {
        return self::sanitize(Markdown::parse((string) $markdown));
    }
}
