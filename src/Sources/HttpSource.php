<?php

namespace NineteenNinetyFour\Ingest\Sources;

use NineteenNinetyFour\Ingest\Contracts\Source;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Formats\ItemsAt;
use NineteenNinetyFour\Ingest\Formats\JsonFormat;
use NineteenNinetyFour\Ingest\Http\Fetcher;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Support\Secrets;

/**
 * A feed URL or an API: headers, query parameters, authentication and
 * pagination. Asks the server whether the feed has changed since last
 * time (ETag / Last-Modified) and stops early when it hasn't.
 *
 * Settings (feed "source"): url, method, headers, query, body, auth,
 * pagination.
 */
class HttpSource implements Source
{
    public function __construct(
        protected Fetcher $fetcher,
        protected Auth $auth,
        protected Formats $formats,
    ) {}

    public static function handle(): string
    {
        return 'url';
    }

    public static function label(): string
    {
        return __('URL or API');
    }

    public function fetch(Feed $feed, string $directory, array $previous = []): Payload
    {
        $source = $feed->source();
        $url = trim((string) ($source['url'] ?? ''));

        if ($url === '') {
            throw new IngestException('The feed has no URL.');
        }

        $auth = $this->auth->apply((array) ($source['auth'] ?? []), $feed->handle());
        $headers = array_merge($this->headers((array) ($source['headers'] ?? [])), $auth['headers']);
        $baseQuery = array_merge((array) Secrets::resolve((array) ($source['query'] ?? [])), $auth['query']);

        $pagination = new Pagination((array) ($source['pagination'] ?? []), $url, $baseQuery);
        [$pageUrl, $query] = $pagination->first();

        $method = strtoupper((string) ($source['method'] ?? 'GET'));
        $body = isset($source['body']) && $source['body'] !== '' ? (string) $source['body'] : null;

        $pages = [];
        $warnings = [];
        $seen = [];
        $validators = [];
        $format = null;

        for ($index = 0; $index < $pagination->maxPages(); $index++) {
            $signature = $pageUrl.'?'.http_build_query($query);
            if (isset($seen[$signature])) {
                $warnings[] = "Pagination returned to a page it had already fetched ({$pageUrl}); stopped there.";

                break;
            }
            $seen[$signature] = true;

            // Only the first page is conditional: later pages are fetched whole.
            $conditional = $index === 0 && ! $pagination->enabled() ? $this->conditionalHeaders($previous) : [];
            $file = $directory.'/page-'.($index + 1);

            $fetched = $this->fetcher->fetch($pageUrl, $file, $headers + $conditional, $query, $method, $body);

            if ($fetched->notModified()) {
                return Payload::unchanged($previous);
            }

            if ($index === 0) {
                $validators = array_filter([
                    'etag' => $fetched->header('etag'),
                    'last_modified' => $fetched->header('last-modified'),
                ]);
            }

            $extension = pathinfo((string) parse_url($fetched->url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: null;
            $pages[] = [
                'path' => basename($file),
                'url' => $fetched->url,
                'content_type' => $fetched->contentType(),
                'extension' => $extension,
            ];

            if (! $pagination->enabled()) {
                break;
            }

            $format ??= ($feed->format()['type'] ?? 'auto') === 'auto'
                ? $this->formats->detect($file, $fetched->contentType(), $extension)
                : $feed->format()['type'];

            $reader = $this->formats->make($format);
            $options = $feed->format() + ['item_path' => null];
            $options['item_path'] ??= $reader->defaultItemPath();

            $document = null;
            $documentOf = function () use (&$document, $reader, $file, $options) {
                if ($document === null) {
                    $document = filesize($file) > JsonFormat::STREAM_ABOVE ? [] : $reader->document($file, $options);
                }

                return $document;
            };

            $count = 0;
            if (in_array($pagination->type(), ['page', 'offset'], true)) {
                $count = filesize($file) > JsonFormat::STREAM_ABOVE
                    ? iterator_count($reader->items($file, $options))
                    : iterator_count(ItemsAt::of($documentOf(), (string) $options['item_path']));
            }

            $next = $pagination->next($index, $fetched, $documentOf, $count);

            if ($next === null) {
                break;
            }

            if ($index + 1 >= $pagination->maxPages()) {
                $warnings[] = "Stopped after {$pagination->maxPages()} pages (the feed's page limit). Raise it if the feed is longer.";
                // A feed cut short is incomplete: nothing will be treated as missing.
                $validators['incomplete'] = true;
            }

            [$pageUrl, $query] = $next;
        }

        return new Payload($pages, $validators, false, $warnings, $url);
    }

    /** @return array<string, string> */
    protected function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            // Saved from the Control Panel as a list of {name, value} rows.
            if (is_array($value)) {
                [$name, $value] = [$value['name'] ?? $value['key'] ?? null, $value['value'] ?? null];
            }
            if ($name === null || $name === '') {
                continue;
            }
            $resolved = Secrets::resolve($value);
            if (Secrets::isReference($value) && ($resolved === null || $resolved === '')) {
                throw new IngestException(sprintf('The environment variable %s, used in the %s header, isn\'t set.', Secrets::name($value), $name));
            }
            $out[(string) $name] = (string) $resolved;
        }

        return $out;
    }

    protected function conditionalHeaders(array $previous): array
    {
        return array_filter([
            'If-None-Match' => $previous['etag'] ?? null,
            'If-Modified-Since' => $previous['last_modified'] ?? null,
        ]);
    }
}
