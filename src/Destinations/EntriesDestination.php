<?php

namespace NineteenNinetyFour\Ingest\Destinations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Map\MapRecord;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

/**
 * Entries in a collection. Settings: collection, blueprint, site.
 *
 * Besides the blueprint's fields a mapping can set slug, date (dated
 * collections), published and parent (structured collections, matched by
 * the parent's key in the same feed or its entry id).
 */
class EntriesDestination implements Destination
{
    public static function handle(): string
    {
        return 'entries';
    }

    public static function label(): string
    {
        return __('Entries');
    }

    public function contentType(): string
    {
        return 'entry';
    }

    public function personal(): bool
    {
        return false;
    }

    protected function collection(Feed $feed): \Statamic\Contracts\Entries\Collection
    {
        $handle = (string) ($feed->destination()['collection'] ?? '');
        $collection = $handle !== '' ? Collections::find($handle) : null;

        if (! $collection) {
            throw new IngestException($handle === '' ? 'Choose a collection for this feed.' : "There's no collection called {$handle}.");
        }

        return $collection;
    }

    protected function blueprint(Feed $feed): Blueprint
    {
        $collection = $this->collection($feed);
        $handle = $feed->destination()['blueprint'] ?? null;
        $blueprint = $handle ? $collection->entryBlueprint($handle) : $collection->entryBlueprint();

        if (! $blueprint) {
            throw new IngestException("The {$collection->title()} collection has no blueprint called {$handle}.");
        }

        return $blueprint;
    }

    public function validate(Feed $feed): void
    {
        $collection = $this->collection($feed);
        $this->blueprint($feed);

        if (! $collection->sites()->contains($feed->site())) {
            throw new IngestException("The {$collection->title()} collection isn't available in the {$feed->site()} site.");
        }
    }

    public function fields(Feed $feed): Collection
    {
        $fields = $this->blueprint($feed)->fields()->all();
        $collection = $this->collection($feed);

        $virtual = ['slug' => ['type' => 'slug', 'display' => __('Slug')], 'published' => ['type' => 'toggle', 'display' => __('Published')]];
        if ($collection->dated()) {
            $virtual['date'] = ['type' => 'date', 'display' => __('Date'), 'time_enabled' => true];
        }
        if ($collection->hasStructure() && $collection->structure()->maxDepth() !== 1) {
            $virtual['parent'] = ['type' => 'text', 'display' => __('Parent')];
        }

        foreach ($virtual as $handle => $config) {
            if (! $fields->has($handle)) {
                $fields->put($handle, new Field($handle, $config));
            }
        }

        return $fields;
    }

    public function find(Feed $feed, string $id): mixed
    {
        $entry = Entry::find($id);

        return $entry && $entry->collectionHandle() === $this->collection($feed)->handle() ? $entry : null;
    }

    public function findBy(Feed $feed, string $field, mixed $value): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection($feed)->handle())
            ->where('site', $feed->site())
            ->where($field, $value)
            ->limit(2)
            ->get();
    }

    public function values(Feed $feed, mixed $content, array $handles): array
    {
        /** @var EntryContract $content */
        $out = [];
        foreach ($handles as $handle) {
            $out[$handle] = match ($handle) {
                'slug' => $content->slug(),
                'published' => $content->published(),
                'date' => $content->hasDate() ? $content->date()?->utc()->format('Y-m-d H:i') : null,
                'parent' => $content->parent()?->id(),
                default => $content->get($handle),
            };
        }

        return $out;
    }

    public function make(Feed $feed, array $values, Context $context): mixed
    {
        $collection = $this->collection($feed);

        $entry = Entry::make()
            ->collection($collection)
            ->blueprint($this->blueprint($feed)->handle())
            ->locale($feed->site())
            ->published((bool) ($feed->sync()['publish_new'] ?? true));

        if ($context->origin ?? null) {
            $entry->origin($context->origin);
        }

        if ($collection->dated() && ! array_key_exists('date', $values)) {
            $entry->date(CarbonImmutable::now());
        }

        return $entry;
    }

    public function fill(Feed $feed, mixed $content, array $values, Context $context): void
    {
        /** @var EntryContract $content */
        foreach ($values as $handle => $value) {
            match ($handle) {
                'slug' => $value ? $content->slug($this->uniqueSlug($feed, (string) $value, $content)) : null,
                'published' => $content->published((bool) $value),
                'date' => $value ? $content->date(Transforms::parseDate((string) $value, null, 'UTC')) : null,
                'parent' => $this->parent($feed, $content, $value, $context),
                default => $value === null ? $content->remove($handle) : $content->set($handle, $value),
            };
        }

        if (! $content->slug()) {
            $title = Value::text($content->get('title')) ?: ($context->item ? substr(sha1(json_encode($context->item)), 0, 8) : 'item');
            $content->slug($this->uniqueSlug($feed, Str::slug($title), $content));
        }
    }

    public function save(mixed $content): void
    {
        config('ingest.quiet_saves', true) ? $content->saveQuietly() : $content->save();
    }

    public function id(mixed $content): string
    {
        return (string) $content->id();
    }

    public function title(mixed $content): ?string
    {
        return Value::text($content->get('title')) ?: $content->slug();
    }

    public function isPublished(mixed $content): bool
    {
        return (bool) $content->published();
    }

    public function setPublished(mixed $content, bool $published): void
    {
        $content->published($published);
    }

    public function delete(mixed $content): void
    {
        config('ingest.quiet_saves', true) ? $content->deleteQuietly() : $content->delete();
    }

    public function snapshot(mixed $content): array
    {
        /** @var EntryContract $content */
        return [
            'id' => $content->id(),
            'collection' => $content->collectionHandle(),
            'blueprint' => $content->blueprint()?->handle(),
            'locale' => $content->locale(),
            'origin' => $content->origin()?->id(),
            'slug' => $content->slug(),
            'published' => $content->published(),
            'date' => $content->hasDate() ? $content->date()?->toIso8601String() : null,
            'data' => $content->data()->all(),
        ];
    }

    public function restore(Feed $feed, array $snapshot): mixed
    {
        $entry = Entry::make()
            ->id($snapshot['id'])
            ->collection($snapshot['collection'])
            ->locale($snapshot['locale'])
            ->slug($snapshot['slug'])
            ->published((bool) $snapshot['published'])
            ->data((array) $snapshot['data']);

        if ($snapshot['blueprint'] ?? null) {
            $entry->blueprint($snapshot['blueprint']);
        }
        if ($snapshot['origin'] ?? null) {
            $entry->origin($snapshot['origin']);
        }
        if ($snapshot['date'] ?? null) {
            $entry->date(CarbonImmutable::parse($snapshot['date']));
        }

        $this->save($entry);

        return $entry;
    }

    private function uniqueSlug(Feed $feed, string $slug, EntryContract $entry): string
    {
        $base = Str::slug($slug) ?: 'item';
        $slug = $base;
        $collection = $this->collection($feed)->handle();

        for ($i = 2; $i < 1000; $i++) {
            $taken = Entry::query()->where('collection', $collection)->where('site', $feed->site())->where('slug', $slug)->get()
                ->contains(fn ($other) => $other->id() !== $entry->id());

            if (! $taken) {
                return $slug;
            }

            $slug = $base.'-'.$i;
        }

        return $base.'-'.Str::random(6);
    }

    /**
     * A parent by entry id, or by the parent item's key in this feed.
     */
    private function parent(Feed $feed, EntryContract $entry, mixed $value, Context $context): void
    {
        if (Value::isEmpty($value)) {
            return;
        }

        $parent = Entry::find((string) $value) ?? $this->ownedBy($feed, (string) $value);

        if (! $parent) {
            $context->warn("Parent \"{$value}\" isn't there yet; it will be set on a later run if it arrives.");

            return;
        }

        // The tree is updated after the entry is saved (it needs an id first).
        $context->parent = $parent->id();
    }

    /**
     * Place the entry under its parent in the collection's structure.
     */
    public function afterSave(Feed $feed, mixed $entry, Context $context): void
    {
        if (! $context->parent || $context->dryRun) {
            return;
        }

        $tree = $this->collection($feed)->structure()->in($feed->site());

        if ($tree->find($entry->id())) {
            if ($tree->find($entry->id())->parent()?->id() !== $context->parent) {
                $tree->move($entry->id(), $context->parent);
            }
        } else {
            $tree->appendTo($context->parent, $entry);
        }

        $tree->save();
    }

    private function ownedBy(Feed $feed, string $key): ?EntryContract
    {
        $record = app(ItemMap::class)->find($feed->handle(), $feed->site(), MapRecord::hashKey([$key]));

        return $record ? Entry::find($record->contentId) : null;
    }
}
