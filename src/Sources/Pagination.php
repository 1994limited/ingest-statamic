<?php

namespace NineteenNinetyFour\Ingest\Sources;

use NineteenNinetyFour\Ingest\Http\Fetched;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Url;

/**
 * Works out the next page to fetch.
 *
 *   next_url     a link in the body (path), resolved against the page's URL
 *   link_header  the Link: <…>; rel="next" header
 *   page         a page number: a query parameter (param) or {page} in the URL
 *   offset       an offset: a query parameter (param) or {offset} in the URL
 *   cursor       a token from the body (path): a query parameter or {cursor} in the URL
 *
 * Page, offset and cursor stop on an empty page; page and offset also stop
 * at a total or last page read from the body (total_path, last_page_path).
 */
class Pagination
{
    public function __construct(private array $settings, private string $url, private array $query) {}

    public function type(): string
    {
        return (string) ($this->settings['type'] ?? 'none');
    }

    public function enabled(): bool
    {
        return ! in_array($this->type(), ['none', '', null], true);
    }

    public function maxPages(): int
    {
        return max(1, (int) ($this->settings['max_pages'] ?? 500));
    }

    /**
     * The first request.
     *
     * @return array{0: string, 1: array}
     */
    public function first(): array
    {
        return match ($this->type()) {
            'page' => $this->at('page', (int) ($this->settings['start'] ?? 1)),
            'offset' => $this->at('offset', (int) ($this->settings['start'] ?? 0)),
            'cursor' => isset($this->settings['first']) || str_contains($this->url, '{cursor}')
                ? $this->at('cursor', (string) ($this->settings['first'] ?? ''))
                : [$this->url, $this->query],
            default => [$this->url, $this->query],
        };
    }

    /**
     * The request after this page, or null when this was the last.
     *
     * @param  int  $index  this page's position, from 0
     * @param  callable(): array  $document  the page decoded (only called when needed)
     * @return array{0: string, 1: array}|null
     */
    public function next(int $index, Fetched $page, callable $document, int $items): ?array
    {
        $s = $this->settings;

        switch ($this->type()) {
            case 'next_url':
                $next = $this->text(Path::get($document(), (string) ($s['path'] ?? 'next')));

                return $next ? [Url::resolve($page->url, $next), []] : null;

            case 'link_header':
                $next = self::linkHeaderNext((string) $page->header('link'));

                return $next ? [Url::resolve($page->url, $next), []] : null;

            case 'page':
                if ($items === 0) {
                    return null;
                }
                $start = (int) ($s['start'] ?? 1);
                $current = $start + $index;
                if (($last = $this->number($document, $s['last_page_path'] ?? null)) !== null && $current >= $last) {
                    return null;
                }
                if (($total = $this->number($document, $s['total_path'] ?? null)) !== null && ($size = (int) ($s['size'] ?? $items)) > 0 && $current * $size >= $total) {
                    return null;
                }

                return $this->at('page', $current + 1);

            case 'offset':
                $size = (int) ($s['size'] ?? 0) ?: $items;
                if ($items === 0 || ($s['size'] ?? null) && $items < $size) {
                    return null;
                }
                $offset = (int) ($s['start'] ?? 0) + ($index + 1) * $size;
                if (($total = $this->number($document, $s['total_path'] ?? null)) !== null && $offset >= $total) {
                    return null;
                }

                return $this->at('offset', $offset);

            case 'cursor':
                if (isset($s['has_more_path']) && ! filter_var(Path::get($document(), $s['has_more_path']), FILTER_VALIDATE_BOOL)) {
                    return null;
                }
                $cursor = $this->text(Path::get($document(), (string) ($s['path'] ?? 'next_cursor')));

                return $cursor !== null && $cursor !== '' ? $this->at('cursor', $cursor) : null;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function at(string $name, int|string $value): array
    {
        $placeholder = '{'.$name.'}';

        if (str_contains($this->url, $placeholder)) {
            return [str_replace($placeholder, rawurlencode((string) $value), $this->url), $this->query];
        }

        $query = $this->query;
        $query[(string) ($this->settings['param'] ?? $name)] = $value;

        if ($name === 'offset' && isset($this->settings['size_param'], $this->settings['size'])) {
            $query[$this->settings['size_param']] = (int) $this->settings['size'];
        }
        if ($name === 'page' && isset($this->settings['size_param'], $this->settings['size'])) {
            $query[$this->settings['size_param']] = (int) $this->settings['size'];
        }

        return [$this->url, $query];
    }

    private function number(callable $document, ?string $path): ?int
    {
        if (! $path) {
            return null;
        }

        $value = $this->text(Path::get($document(), $path));

        return is_numeric($value) ? (int) $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['@href'] ?? $value['#text'] ?? null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public static function linkHeaderNext(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            if (preg_match('/<([^>]+)>\s*;(.*)/', trim($part), $m) && preg_match('/rel\s*=\s*"?([^";]*)"?/i', $m[2], $rel) && in_array('next', preg_split('/\s+/', strtolower($rel[1])), true)) {
                return $m[1];
            }
        }

        return null;
    }
}
