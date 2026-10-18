<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use Carbon\Carbon;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;

class EntriesMapper extends RelationshipMapper
{
    protected function noun(): string
    {
        return 'entry';
    }

    protected function collections(Field $field, array $mapping): array
    {
        return array_values(array_filter((array) ($mapping['collection'] ?? $field->get('collections') ?? [])));
    }

    protected function find(string $value, string $match, Field $field, array $mapping, Context $context): ?string
    {
        if ($match === 'id') {
            return Entry::find($value)?->id();
        }

        $query = Entry::query()->where('site', $context->site());

        if ($collections = $this->collections($field, $mapping)) {
            $query->whereIn('collection', $collections);
        }

        $field = $match === 'slug' ? 'slug' : $match;
        $value = $match === 'slug' ? Str::slug($value) : $value;

        return $query->where($field, $value)->first()?->id();
    }

    protected function create(string $value, Field $field, array $mapping, Context $context): ?string
    {
        $collection = $this->collections($field, $mapping)[0] ?? null;

        if (! $collection) {
            throw new FieldError("Creating entries needs a collection: set one on the {$field->display()} field.");
        }

        $entry = Entry::make()
            ->collection($collection)
            ->locale($context->site())
            ->slug($this->uniqueSlug($value, $collection, $context->site()))
            ->published(true)
            ->data(['title' => $value]);

        if ($entry->collection()->dated()) {
            $entry->date(Carbon::now());
        }

        config('ingest.quiet_saves', true) ? $entry->saveQuietly() : $entry->save();
        $context->created[] = ['type' => 'entry', 'id' => $entry->id()];

        return $entry->id();
    }

    private function uniqueSlug(string $title, string $collection, string $site): string
    {
        $base = Str::slug($title) ?: 'item';
        $slug = $base;

        for ($i = 2; Entry::query()->where('collection', $collection)->where('site', $site)->where('slug', $slug)->count() > 0; $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
