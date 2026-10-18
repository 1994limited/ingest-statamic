<?php

namespace NineteenNinetyFour\Ingest\Sources;

/**
 * What a source fetched: one file per page, in order, plus what's needed
 * to ask "has it changed?" next time.
 */
class Payload
{
    /**
     * @param  array<int, array{path: string, url: ?string, content_type: ?string, extension: ?string}>  $pages  paths relative to the run's folder
     * @param  array<string, mixed>  $validators  etag, last_modified
     * @param  string[]  $warnings
     */
    public function __construct(
        public array $pages = [],
        public array $validators = [],
        public bool $notModified = false,
        public array $warnings = [],
        public ?string $baseUrl = null,
    ) {}

    public static function unchanged(array $validators): self
    {
        return new self([], $validators, true);
    }
}
