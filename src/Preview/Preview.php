<?php

namespace NineteenNinetyFour\Ingest\Preview;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Formats\JsonFormat;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Sources\Sources;
use NineteenNinetyFour\Ingest\Support\Filters;
use NineteenNinetyFour\Ingest\Support\Path;
use Throwable;

/**
 * Reads the first page of a feed for the Control Panel: its structure (to
 * pick the item path), sample items (to pick field paths) and what the
 * mapping makes of them. Nothing is saved, created or downloaded.
 */
class Preview
{
    public const SAMPLE = 25;

    public function __construct(
        private Sources $sources,
        private Formats $formats,
        private Mapper $mapper,
        private Destinations $destinations,
    ) {}

    /**
     * @return array{format: string, item_path: ?string, count: int, items: array, paths: array, tree: array, warnings: string[], mapped?: array}
     */
    public function fetch(Feed $feed, bool $fresh = false): array
    {
        $key = 'ingest:preview:'.$feed->handle().':'.sha1(json_encode([$feed->source(), $feed->format()]));

        if ($fresh) {
            Cache::forget($key);
        }

        $sample = Cache::remember($key, 600, fn () => $this->sample($feed));

        if ($feed->mapping() !== []) {
            $sample['mapped'] = $this->map($feed, $sample['items']);
        }

        return $sample;
    }

    private function sample(Feed $feed): array
    {
        $directory = Ingest::storagePath('preview/'.$feed->handle().'-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        // Only the first page.
        $first = Feed::make($feed->handle(), $feed->toArray())->set('source.pagination.max_pages', 1);

        try {
            $payload = $this->sources->make((string) ($feed->source()['type'] ?? 'url'))->fetch($first, $directory);
            $page = $payload->pages[0] ?? throw new IngestException(__('The source returned nothing.'));
            $file = $directory.'/'.$page['path'];

            $format = ($feed->format()['type'] ?? 'auto') === 'auto'
                ? $this->formats->detect($file, $page['content_type'] ?? null, $page['extension'] ?? null)
                : $feed->format()['type'];

            $reader = $this->formats->make($format);
            $options = $feed->format();
            $itemPath = ($options['item_path'] ?? null) ?: $reader->defaultItemPath();
            $options['item_path'] = $itemPath;

            $tree = filesize($file) <= JsonFormat::STREAM_ABOVE ? $this->tree($reader->document($file, $options)) : [];

            $items = [];
            $count = 0;
            foreach ($reader->items($file, $options) as $item) {
                if ($count < self::SAMPLE) {
                    $items[] = $item;
                }
                $count++;
            }

            $paths = [];
            foreach ($items as $item) {
                foreach (Path::flatten($item) as $path => $value) {
                    $paths[$path] ??= is_scalar($value) || $value === null ? $value : (Path::isList($value) ? '['.count($value).']' : '{…}');
                }
            }

            return [
                'format' => $format,
                'item_path' => $itemPath,
                'count' => $count,
                'items' => $items,
                'paths' => $paths,
                'tree' => $tree,
                'warnings' => $payload->warnings,
                'filtered' => count(array_filter($items, fn ($i) => Filters::passes($i, $feed->filters()))),
            ];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * What the mapping makes of each sample item: field => value, or an error.
     *
     * @return array<int, array{values: array, errors: array, warnings: array}>
     */
    public function map(Feed $feed, array $items): array
    {
        try {
            $fields = $this->destinations->for($feed)->fields($feed);
        } catch (Throwable $e) {
            return [['values' => [], 'errors' => ['destination' => $e->getMessage()], 'warnings' => []]];
        }

        $context = new Context($feed, null, true);

        return array_map(function ($item) use ($feed, $fields, $context) {
            $context->forItem($item);
            $mapped = $this->mapper->map($feed->mapping(), $fields, $item, $context);

            return ['values' => $mapped->values, 'errors' => $mapped->errors, 'warnings' => $context->warnings];
        }, $items);
    }

    /**
     * The document's shape for the item-path picker: each path that holds
     * a list or an object, and how many items it has.
     *
     * @return array<int, array{path: string, count: int, sample: array}>
     */
    private function tree(array $document, string $prefix = '', int $depth = 0): array
    {
        $out = [];

        if ($depth > 6) {
            return $out;
        }

        foreach ($document as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix.'/'.$key;

            if (Path::isList($value)) {
                $out[] = ['path' => $path, 'count' => count($value), 'keys' => is_array($value[0] ?? null) ? array_slice(array_keys($value[0]), 0, 8) : []];

                if (is_array($value[0] ?? null)) {
                    $out = array_merge($out, $this->tree($value[0], $path, $depth + 1));
                }

                continue;
            }

            if ($depth < 3) {
                $out[] = ['path' => $path, 'count' => 1, 'keys' => array_slice(array_keys($value), 0, 8)];
            }

            $out = array_merge($out, $this->tree($value, $path, $depth + 1));
        }

        return $out;
    }
}
