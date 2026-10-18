<?php

namespace NineteenNinetyFour\Ingest\Map;

/**
 * The link between a feed item and the content it became: how a feed
 * finds its items again, knows they're its own, and spots what changed.
 */
class MapRecord
{
    /**
     * @param  array<string, string>  $fieldHashes  field => hash of the value Ingest last wrote
     */
    public function __construct(
        public string $feed,
        public string $site,
        public string $key,
        public string $sourceKey,
        public string $contentType,
        public string $contentId,
        public ?string $itemHash = null,
        public array $fieldHashes = [],
        public ?string $firstRunId = null,
        public ?string $lastSeenRunId = null,
        public ?string $missingSince = null,
        public ?string $state = null,
    ) {}

    /** The stored key for a feed item's key values. */
    public static function hashKey(array $values): string
    {
        return sha1(implode("\x1f", array_map(fn ($v) => trim((string) $v), $values)));
    }

    public function toArray(): array
    {
        return [
            'feed' => $this->feed,
            'site' => $this->site,
            'key' => $this->key,
            'source_key' => $this->sourceKey,
            'content_type' => $this->contentType,
            'content_id' => $this->contentId,
            'item_hash' => $this->itemHash,
            'field_hashes' => $this->fieldHashes,
            'first_run_id' => $this->firstRunId,
            'last_seen_run_id' => $this->lastSeenRunId,
            'missing_since' => $this->missingSince,
            'state' => $this->state,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            feed: (string) $data['feed'],
            site: (string) $data['site'],
            key: (string) $data['key'],
            sourceKey: (string) ($data['source_key'] ?? ''),
            contentType: (string) $data['content_type'],
            contentId: (string) $data['content_id'],
            itemHash: $data['item_hash'] ?? null,
            fieldHashes: is_array($data['field_hashes'] ?? null) ? $data['field_hashes'] : (json_decode((string) ($data['field_hashes'] ?? ''), true) ?: []),
            firstRunId: $data['first_run_id'] ?? null,
            lastSeenRunId: $data['last_seen_run_id'] ?? null,
            missingSince: $data['missing_since'] ?? null,
            state: $data['state'] ?? null,
        );
    }
}
