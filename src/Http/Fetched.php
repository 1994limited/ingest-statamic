<?php

namespace NineteenNinetyFour\Ingest\Http;

/**
 * A downloaded response: where it was saved, and what the server said.
 */
class Fetched
{
    /**
     * @param  array<string, string>  $headers  lower-case names
     */
    public function __construct(
        public readonly ?string $path,
        public readonly int $status,
        public readonly string $url,
        public readonly array $headers,
        public readonly int $size,
    ) {}

    public function notModified(): bool
    {
        return $this->status === 304;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): ?string
    {
        $type = $this->header('content-type');

        return $type ? strtolower(trim(explode(';', $type)[0])) : null;
    }
}
