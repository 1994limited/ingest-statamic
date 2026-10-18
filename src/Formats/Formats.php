<?php

namespace NineteenNinetyFour\Ingest\Formats;

use NineteenNinetyFour\Ingest\Contracts\Format;
use NineteenNinetyFour\Ingest\IngestException;

/**
 * The formats Ingest can read. Add your own with Ingest::format().
 */
class Formats
{
    /** @var array<string, class-string<Format>> */
    private array $formats = [];

    public function __construct()
    {
        foreach ([JsonFormat::class, NdjsonFormat::class, RssFormat::class, AtomFormat::class, XmlFormat::class, CsvFormat::class, BlmFormat::class] as $class) {
            $this->register($class);
        }
    }

    /** @param class-string<Format> $class */
    public function register(string $class): void
    {
        $this->formats[$class::handle()] = $class;
    }

    public function make(string $handle): Format
    {
        if (! isset($this->formats[$handle])) {
            throw new IngestException("Unknown feed format [{$handle}].");
        }

        return app($this->formats[$handle]);
    }

    /** @return array<string, string> handle => label */
    public function options(): array
    {
        return array_map(fn ($class) => $class::label(), $this->formats);
    }

    /**
     * The best format for a file, judged from its first bytes, content type
     * and extension. Refuses an HTML page: a login or error page where a
     * feed should be.
     */
    public function detect(string $file, ?string $contentType = null, ?string $extension = null): string
    {
        $handle = fopen($file, 'rb');
        $head = (string) fread($handle, 4096);
        fclose($handle);

        $head = ltrim($head, "\xEF\xBB\xBF \t\r\n");

        if ($head === '') {
            throw new IngestException('The feed is empty.');
        }

        if (preg_match('/^<(!doctype\s+html|html[\s>])/i', $head)) {
            throw new IngestException('The feed URL returned a web page, not a feed. Check the URL, and whether it needs signing in.');
        }

        $extension = $extension ? strtolower($extension) : null;
        $best = null;
        $score = 0;

        foreach ($this->formats as $handleName => $class) {
            $s = $class::sniff($head, $contentType, $extension);
            if ($s > $score) {
                [$best, $score] = [$handleName, $s];
            }
        }

        if ($best === null) {
            throw new IngestException('Couldn\'t tell what format the feed is. Choose one in the feed\'s settings.');
        }

        return $best;
    }
}
