<?php

namespace NineteenNinetyFour\Ingest\Contracts;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Mapping\Context;
use Statamic\Fields\Field;

/**
 * Where feed items go: entries, terms, globals, users, Cargo products, or
 * your own (Ingest::destination()).
 */
interface Destination
{
    public static function handle(): string;

    public static function label(): string;

    /** The content type recorded in the ownership map: entry, term, global, user. */
    public function contentType(): string;

    /** Throws an IngestException when the feed's destination settings can't work. */
    public function validate(Feed $feed): void;

    /** @return Collection<string, Field> the fields a mapping can fill, by handle */
    public function fields(Feed $feed): Collection;

    public function find(Feed $feed, string $id): mixed;

    /** @return Collection<int, mixed> existing content whose field has this value (to adopt) */
    public function findBy(Feed $feed, string $field, mixed $value): Collection;

    /** @return array<string, mixed> the content's current values for these fields */
    public function values(Feed $feed, mixed $content, array $handles): array;

    /** New, unsaved content. */
    public function make(Feed $feed, array $values, Context $context): mixed;

    /** Set values on content (null removes a value). */
    public function fill(Feed $feed, mixed $content, array $values, Context $context): void;

    public function save(mixed $content): void;

    public function id(mixed $content): string;

    public function title(mixed $content): ?string;

    public function isPublished(mixed $content): bool;

    public function setPublished(mixed $content, bool $published): void;

    public function delete(mixed $content): void;

    /** Everything needed to recreate the content after a delete. */
    public function snapshot(mixed $content): array;

    public function restore(Feed $feed, array $snapshot): mixed;

    /** Whether values may hold personal data (kept briefly in run history). */
    public function personal(): bool;
}
