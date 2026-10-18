<?php

namespace NineteenNinetyFour\Ingest\Formats;

/**
 * RSS 2.0: XML with channel/item as the items, plus a "_feed" set of
 * normalised fields (title, link, id, date, author, summary, content,
 * image, categories) that read the same for RSS and Atom, so mixed feeds
 * can share one mapping.
 */
class RssFormat extends XmlFormat
{
    public static function handle(): string
    {
        return 'rss';
    }

    public static function label(): string
    {
        return 'RSS';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        return preg_match('/<rss[\s>]/i', $head) || preg_match('/<rdf:RDF/i', $head) ? 95 : 0;
    }

    public function defaultItemPath(): ?string
    {
        return 'rss/channel/item';
    }

    protected function finish(array $item): array
    {
        $text = fn ($v) => is_array($v) ? ($v['#text'] ?? null) : $v;
        $guid = $text($item['guid'] ?? null);

        $image = $item['media:content']['@url'] ?? $item['media:thumbnail']['@url'] ?? $item['enclosure']['@url'] ?? null;
        if (isset($item['media:content'][0]['@url'])) {
            $image = $item['media:content'][0]['@url'];
        }
        if (isset($item['enclosure']['@type']) && ! str_starts_with($item['enclosure']['@type'], 'image/')) {
            $image = $item['media:content']['@url'] ?? $item['media:thumbnail']['@url'] ?? null;
        }

        $categories = $item['category'] ?? [];
        $categories = array_values(array_filter(array_map($text, is_array($categories) && array_is_list($categories) ? $categories : [$categories])));

        $item['_feed'] = [
            'id' => $guid ?: $text($item['link'] ?? null),
            'title' => $text($item['title'] ?? null),
            'link' => $text($item['link'] ?? null),
            'date' => $text($item['pubDate'] ?? $item['dc:date'] ?? null),
            'author' => $text($item['dc:creator'] ?? $item['author'] ?? null),
            'summary' => $text($item['description'] ?? null),
            'content' => $text($item['content:encoded'] ?? $item['description'] ?? null),
            'image' => $image,
            'categories' => $categories,
            'enclosure' => isset($item['enclosure']['@url']) ? [
                'url' => $item['enclosure']['@url'],
                'type' => $item['enclosure']['@type'] ?? null,
                'length' => $item['enclosure']['@length'] ?? null,
            ] : null,
        ];

        return $item;
    }
}
