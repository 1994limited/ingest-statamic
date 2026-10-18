<?php

namespace NineteenNinetyFour\Ingest\Sources;

use NineteenNinetyFour\Ingest\Contracts\Source;
use NineteenNinetyFour\Ingest\IngestException;

/**
 * The places feeds can come from. Add your own with Ingest::source().
 */
class Sources
{
    /** @var array<string, class-string<Source>> */
    private array $sources = [];

    public function __construct()
    {
        foreach ([HttpSource::class, FileSource::class, PathSource::class, GoogleSheetsSource::class] as $class) {
            $this->register($class);
        }
    }

    /** @param class-string<Source> $class */
    public function register(string $class): void
    {
        $this->sources[$class::handle()] = $class;
    }

    public function make(string $handle): Source
    {
        // "api" reads the same as "url"; the Control Panel just shows more options.
        $handle = $handle === 'api' ? 'url' : $handle;

        if (! isset($this->sources[$handle])) {
            throw new IngestException("Unknown feed source [{$handle}].");
        }

        return app($this->sources[$handle]);
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(fn ($class) => $class::label(), $this->sources);
    }
}
