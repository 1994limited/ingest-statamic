<?php

namespace NineteenNinetyFour\Ingest\Destinations;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Mapping\Context;
use Statamic\Facades\GlobalSet;

/**
 * One global set, in the feed's site: a feed whose single item fills it
 * (opening hours, exchange rates, a notice from a spreadsheet). Settings:
 * set. A globals feed never deletes or unpublishes anything.
 */
class GlobalsDestination implements Destination
{
    public const KEY = 'global';

    public static function handle(): string
    {
        return 'globals';
    }

    public static function label(): string
    {
        return __('Globals');
    }

    public function contentType(): string
    {
        return 'global';
    }

    public function personal(): bool
    {
        return false;
    }

    private function set(Feed $feed): \Statamic\Contracts\Globals\GlobalSet
    {
        $handle = (string) ($feed->destination()['set'] ?? '');
        $set = $handle !== '' ? GlobalSet::findByHandle($handle) : null;

        if (! $set) {
            throw new IngestException($handle === '' ? 'Choose a global set for this feed.' : "There's no global set called {$handle}.");
        }

        return $set;
    }

    private function variables(Feed $feed): mixed
    {
        $set = $this->set($feed);
        $variables = $set->in($feed->site());

        if (! $variables) {
            throw new IngestException("The {$set->title()} globals aren't available in the {$feed->site()} site.");
        }

        return $variables;
    }

    public function validate(Feed $feed): void
    {
        $this->variables($feed);
    }

    public function fields(Feed $feed): Collection
    {
        return $this->set($feed)->blueprint()?->fields()->all() ?? collect();
    }

    public function find(Feed $feed, string $id): mixed
    {
        return $this->variables($feed);
    }

    /** The set's values always exist: the first run updates them. */
    public function existing(Feed $feed, string $key = ''): mixed
    {
        return $this->variables($feed);
    }

    public function findBy(Feed $feed, string $field, mixed $value): Collection
    {
        return collect([$this->variables($feed)]);
    }

    public function values(Feed $feed, mixed $content, array $handles): array
    {
        return collect($handles)->mapWithKeys(fn ($h) => [$h => $content->get($h)])->all();
    }

    public function make(Feed $feed, array $values, Context $context): mixed
    {
        return $this->variables($feed);
    }

    public function fill(Feed $feed, mixed $content, array $values, Context $context): void
    {
        foreach ($values as $handle => $value) {
            $value === null ? $content->remove($handle) : $content->set($handle, $value);
        }
    }

    public function save(mixed $content): void
    {
        config('ingest.quiet_saves', true) && method_exists($content, 'saveQuietly') ? $content->saveQuietly() : $content->save();
    }

    public function id(mixed $content): string
    {
        return (string) $content->id();
    }

    public function title(mixed $content): ?string
    {
        return $content->globalSet()->title();
    }

    public function isPublished(mixed $content): bool
    {
        return true;
    }

    public function setPublished(mixed $content, bool $published): void {}

    public function delete(mixed $content): void
    {
        // Globals are never deleted by a feed.
    }

    public function snapshot(mixed $content): array
    {
        return ['data' => $content->data()->all()];
    }

    public function restore(Feed $feed, array $snapshot): mixed
    {
        $variables = $this->variables($feed);
        $variables->data((array) ($snapshot['data'] ?? []));
        $this->save($variables);

        return $variables;
    }
}
