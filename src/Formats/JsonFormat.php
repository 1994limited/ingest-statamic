<?php

namespace NineteenNinetyFour\Ingest\Formats;

use Generator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use NineteenNinetyFour\Ingest\Contracts\Format;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Support\Path;
use Throwable;

class JsonFormat implements Format
{
    /** Files above this size are streamed rather than decoded at once. */
    public const STREAM_ABOVE = 20 * 1024 * 1024;

    public static function handle(): string
    {
        return 'json';
    }

    public static function label(): string
    {
        return 'JSON';
    }

    public static function sniff(string $head, ?string $contentType, ?string $extension): int
    {
        $head = ltrim($head);

        if (! str_starts_with($head, '{') && ! str_starts_with($head, '[')) {
            return 0;
        }

        return $contentType === 'application/json' || $extension === 'json' ? 100 : 80;
    }

    public function defaultItemPath(): ?string
    {
        return null;
    }

    public function items(string $file, array $options): Generator
    {
        $path = (string) ($options['item_path'] ?? '');

        if (filesize($file) > self::STREAM_ABOVE) {
            yield from $this->stream($file, $path);

            return;
        }

        yield from ItemsAt::of($this->document($file, $options), $path);
    }

    public function document(string $file, array $options): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable $e) {
            throw new IngestException("The JSON isn't valid: {$e->getMessage()}.");
        }

        return is_array($data) ? $data : ['value' => $data];
    }

    private function stream(string $file, string $path): Generator
    {
        $segments = array_map(fn ($s) => in_array($s, ['*', '~'], true) ? '-' : $s, Path::segments($path));
        $segments = array_values(array_filter($segments, fn ($s, $i) => ! ($s === '-' && $i === count($segments) - 1), ARRAY_FILTER_USE_BOTH));
        $pointer = $segments === [] ? '' : '/'.implode('/', array_map(fn ($s) => str_replace(['~', '/'], ['~0', '~1'], $s), $segments));

        try {
            foreach (Items::fromFile($file, ['pointer' => $pointer, 'decoder' => new ExtJsonDecoder(true)]) as $item) {
                yield is_array($item) ? $item : ['value' => $item];
            }
        } catch (Throwable $e) {
            throw new IngestException("The JSON isn't valid: {$e->getMessage()}.");
        }
    }
}
