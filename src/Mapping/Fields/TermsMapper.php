<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use Statamic\Facades\Term;
use Statamic\Fields\Field;

/**
 * Terms are stored by slug when the field has one taxonomy, and as
 * "taxonomy::slug" when it has several.
 */
class TermsMapper extends RelationshipMapper
{
    protected function noun(): string
    {
        return 'term';
    }

    private function taxonomies(Field $field, array $mapping): array
    {
        return array_values(array_filter((array) ($mapping['taxonomy'] ?? $field->get('taxonomies') ?? [])));
    }

    private function reference(Field $field, string $taxonomy, string $slug): string
    {
        return count((array) $field->get('taxonomies', [])) > 1 ? "{$taxonomy}::{$slug}" : $slug;
    }

    protected function find(string $value, string $match, Field $field, array $mapping, Context $context): ?string
    {
        foreach ($this->taxonomies($field, $mapping) as $taxonomy) {
            $term = match ($match) {
                'id' => Term::find(str_contains($value, '::') ? $value : "{$taxonomy}::{$value}"),
                'slug' => Term::find("{$taxonomy}::".Str::slug($value)),
                default => Term::query()->where('taxonomy', $taxonomy)->where($match, $value)->first(),
            };

            if ($term) {
                return $this->reference($field, $taxonomy, $term->slug());
            }
        }

        return null;
    }

    protected function create(string $value, Field $field, array $mapping, Context $context): ?string
    {
        $taxonomy = $this->taxonomies($field, $mapping)[0] ?? null;

        if (! $taxonomy) {
            throw new FieldError("Creating terms needs a taxonomy: set one on the {$field->display()} field.");
        }

        $slug = Str::slug($value) ?: 'term';
        $term = Term::make()->taxonomy($taxonomy)->slug($slug);
        $term->dataForLocale($term->defaultLocale(), ['title' => $value]);

        config('ingest.quiet_saves', true) ? $term->saveQuietly() : $term->save();
        $context->created[] = ['type' => 'term', 'id' => $term->id()];

        return $this->reference($field, $taxonomy, $slug);
    }
}
