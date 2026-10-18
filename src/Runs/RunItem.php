<?php

namespace NineteenNinetyFour\Ingest\Runs;

/**
 * What a run did to one item. "changes" holds old and new values of the
 * fields that changed, which is what a dry run shows and what undo puts
 * back. "snapshot" holds a removed item's whole data, so undo can recreate it.
 */
class RunItem
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const CONFLICT = 'conflict';

    public const UNPUBLISHED = 'unpublished';

    public const DELETED = 'deleted';

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [old, new]
     * @param  string[]  $errors
     * @param  string[]  $warnings
     * @param  array<string, mixed>|null  $snapshot
     */
    public function __construct(
        public string $runId,
        public string $key,
        public string $action,
        public ?string $contentType = null,
        public ?string $contentId = null,
        public ?string $title = null,
        public array $changes = [],
        public array $errors = [],
        public array $warnings = [],
        public ?array $snapshot = null,
        public ?int $position = null,
        public bool $personal = false,
    ) {}

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'key' => $this->key,
            'action' => $this->action,
            'content_type' => $this->contentType,
            'content_id' => $this->contentId,
            'title' => $this->title,
            'changes' => $this->changes,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'snapshot' => $this->snapshot,
            'position' => $this->position,
            'personal' => $this->personal,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            runId: (string) $data['run_id'],
            key: (string) $data['key'],
            action: (string) $data['action'],
            contentType: $data['content_type'] ?? null,
            contentId: $data['content_id'] ?? null,
            title: $data['title'] ?? null,
            changes: (array) ($data['changes'] ?? []),
            errors: (array) ($data['errors'] ?? []),
            warnings: (array) ($data['warnings'] ?? []),
            snapshot: $data['snapshot'] ?? null,
            position: isset($data['position']) ? (int) $data['position'] : null,
            personal: (bool) ($data['personal'] ?? false),
        );
    }
}
