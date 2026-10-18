<?php

namespace NineteenNinetyFour\Ingest\Destinations;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Contracts\Destination;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Field;

/**
 * Terms in a taxonomy. Settings: taxonomy, blueprint, site. Values are
 * saved in the feed's site; the slug is set when the term is created.
 */
class TermsDestination implements Destination
{
    public static function handle(): string
    {
        return 'terms';
    }

    public static function label(): string
    {
        return __('Taxonomy terms');
    }

    public function contentType(): string
    {
        return 'term';
    }

    public function personal(): bool
    {
        return false;
    }

    private function taxonomy(Feed $feed): \Statamic\Contracts\Taxonomies\Taxonomy
    {
        $handle = (string) ($feed->destination()['taxonomy'] ?? '');
        $taxonomy = $handle !== '' ? Taxonomy::find($handle) : null;

        if (! $taxonomy) {
            throw new IngestException($handle === '' ? 'Choose a taxonomy for this feed.' : "There's no taxonomy called {$handle}.");
        }

        return $taxonomy;
    }

    public function validate(Feed $feed): void
    {
        $this->taxonomy($feed);
    }

    public function fields(Feed $feed): Collection
    {
        $taxonomy = $this->taxonomy($feed);
        $handle = $feed->destination()['blueprint'] ?? null;
        $blueprint = $handle ? $taxonomy->termBlueprint($handle) : $taxonomy->termBlueprint();
        $fields = $blueprint->fields()->all();

        if (! $fields->has('slug')) {
            $fields->put('slug', new Field('slug', ['type' => 'slug', 'display' => __('Slug')]));
        }

        return $fields;
    }

    public function find(Feed $feed, string $id): mixed
    {
        $term = Term::find($id);

        return $term?->in($feed->site());
    }

    public function findBy(Feed $feed, string $field, mixed $value): Collection
    {
        if ($field === 'slug') {
            return collect([Term::find($this->taxonomy($feed)->handle().'::'.Str::slug(Value::text($value)))])->filter()->map->in($feed->site());
        }

        return Term::query()->where('taxonomy', $this->taxonomy($feed)->handle())->where($field, $value)->limit(2)->get()->map->in($feed->site());
    }

    public function values(Feed $feed, mixed $content, array $handles): array
    {
        $out = [];
        foreach ($handles as $handle) {
            $out[$handle] = $handle === 'slug' ? $content->slug() : $content->get($handle);
        }

        return $out;
    }

    public function make(Feed $feed, array $values, Context $context): mixed
    {
        $taxonomy = $this->taxonomy($feed);
        $slug = Str::slug(Value::text($values['slug'] ?? $values['title'] ?? '')) ?: substr(sha1(json_encode($context->item)), 0, 8);

        if (Term::find($taxonomy->handle().'::'.$slug)) {
            throw new IngestException("A {$taxonomy->title()} term with the slug {$slug} already exists. Set the feed to adopt existing terms by slug.");
        }

        $term = Term::make()->taxonomy($taxonomy)->slug($slug);
        if ($blueprint = $feed->destination()['blueprint'] ?? null) {
            $term->blueprint($blueprint);
        }

        return $term->in($feed->site());
    }

    public function fill(Feed $feed, mixed $content, array $values, Context $context): void
    {
        foreach ($values as $handle => $value) {
            if ($handle === 'slug') {
                continue;
            }
            $value === null ? $content->remove($handle) : $content->set($handle, $value);
        }
    }

    public function save(mixed $content): void
    {
        $term = $content->term();
        config('ingest.quiet_saves', true) ? $term->saveQuietly() : $term->save();
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
        $term = $content->term();
        config('ingest.quiet_saves', true) ? $term->deleteQuietly() : $term->delete();
    }

    public function snapshot(mixed $content): array
    {
        $term = $content->term();

        return [
            'taxonomy' => $term->taxonomyHandle(),
            'slug' => $term->slug(),
            'blueprint' => $term->blueprint()?->handle(),
            'localizations' => $term->localizations()->map(fn ($l) => $l->data()->all())->all(),
        ];
    }

    public function restore(Feed $feed, array $snapshot): mixed
    {
        $term = Term::make()->taxonomy($snapshot['taxonomy'])->slug($snapshot['slug']);
        if ($snapshot['blueprint'] ?? null) {
            $term->blueprint($snapshot['blueprint']);
        }
        foreach ((array) $snapshot['localizations'] as $locale => $data) {
            $term->dataForLocale($locale, (array) $data);
        }

        config('ingest.quiet_saves', true) ? $term->saveQuietly() : $term->save();

        return $term->in($feed->site());
    }
}
