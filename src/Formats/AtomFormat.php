<?php

namespace NineteenNinetyFour\Ingest\Formats;

/**
 * Atom: XML with feed/entry as the items, plus the same "_feed" fields as
 * RSS.
 */
class AtomFormat extends XmlFormat
{
    public static function handle(): string
    {
        return 'atom';
    }

    public static function label(): string
    {
        return 'Atom';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        return preg_match('/<feed[^>]+xmlns=["\']http:\/\/www\.w3\.org\/2005\/Atom/i', $head) ? 95 : 0;
    }

    public function defaultItemPath(): ?string
    {
        return 'feed/entry';
    }

    protected function finish(array $item): array
    {
        $text = fn ($v) => is_array($v) ? ($v['#text'] ?? null) : $v;

        $links = $item['link'] ?? [];
        $links = is_array($links) && array_is_list($links) ? $links : [$links];
        $link = null;
        $image = null;
        foreach ($links as $candidate) {
            $rel = $candidate['@rel'] ?? 'alternate';
            if ($rel === 'alternate' && $link === null) {
                $link = $candidate['@href'] ?? null;
            }
            if ($rel === 'enclosure' && str_starts_with((string) ($candidate['@type'] ?? ''), 'image/')) {
                $image = $candidate['@href'] ?? null;
            }
        }

        $categories = $item['category'] ?? [];
        $categories = is_array($categories) && array_is_list($categories) ? $categories : [$categories];

        $author = $item['author'] ?? null;
        if (is_array($author) && array_is_list($author)) {
            $author = $author[0];
        }

        $item['_feed'] = [
            'id' => $text($item['id'] ?? null) ?: $link,
            'title' => $text($item['title'] ?? null),
            'link' => $link,
            'date' => $text($item['published'] ?? $item['updated'] ?? null),
            'author' => is_array($author) ? ($author['name'] ?? null) : $author,
            'summary' => $text($item['summary'] ?? null),
            'content' => $text($item['content'] ?? $item['summary'] ?? null),
            'image' => $image ?? ($item['media:thumbnail']['@url'] ?? null),
            'categories' => array_values(array_filter(array_map(fn ($c) => is_array($c) ? ($c['@term'] ?? null) : $c, $categories))),
            'enclosure' => null,
        ];

        return $item;
    }
}
