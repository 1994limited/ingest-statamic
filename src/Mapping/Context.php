<?php

namespace NineteenNinetyFour\Ingest\Mapping;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * What a mapper or transform knows about the item being processed.
 */
class Context
{
    /** @var array<string, mixed> the feed item as read */
    public array $item = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var array<int, array{type: string, id: string}> content created on the side (relationships, assets) */
    public array $created = [];

    /** @var array<string, mixed> the existing content's values, for append and merge modes */
    public array $current = [];

    /** The entry a new localization is made from (a feed that localizes another). */
    public mixed $origin = null;

    /** The parent entry's id, for structured collections. */
    public ?string $parent = null;

    /** Whether the item is being created (rather than updated). */
    public bool $isNew = false;

    public function __construct(
        public Feed $feed,
        public ?Run $run = null,
        public bool $dryRun = false,
        public ?string $baseUrl = null,
    ) {}

    public function site(): string
    {
        return $this->feed->site();
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function forItem(array $item): self
    {
        $this->item = $item;
        $this->warnings = [];
        $this->created = [];
        $this->current = [];
        $this->origin = null;
        $this->parent = null;
        $this->isNew = false;

        return $this;
    }
}
