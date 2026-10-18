<?php

namespace NineteenNinetyFour\Ingest\Mapping;

use Statamic\Fields\Field;

/**
 * Which mapper handles which fieldtype. Add your own with
 * Ingest::fieldMapper('my_fieldtype', MyMapper::class).
 */
class FieldMappers
{
    /** @var array<string, class-string<FieldMapper>> */
    private array $mappers = [
        'text' => Fields\TextMapper::class,
        'textarea' => Fields\TextMapper::class,
        'slug' => Fields\SlugMapper::class,
        'color' => Fields\TextMapper::class,
        'link' => Fields\TextMapper::class,
        'video' => Fields\TextMapper::class,
        'html' => Fields\TextMapper::class,
        'code' => Fields\CodeMapper::class,
        'integer' => Fields\NumberMapper::class,
        'float' => Fields\NumberMapper::class,
        'range' => Fields\NumberMapper::class,
        'toggle' => Fields\ToggleMapper::class,
        'select' => Fields\OptionsMapper::class,
        'radio' => Fields\OptionsMapper::class,
        'button_group' => Fields\OptionsMapper::class,
        'checkboxes' => Fields\OptionsMapper::class,
        'list' => Fields\ListMapper::class,
        'taggable' => Fields\ListMapper::class,
        'tags' => Fields\ListMapper::class,
        'array' => Fields\ArrayMapper::class,
        'table' => Fields\TableMapper::class,
        'date' => Fields\DateMapper::class,
        'time' => Fields\TimeMapper::class,
        'markdown' => Fields\MarkdownMapper::class,
        'bard' => Fields\BardMapper::class,
        'assets' => Fields\AssetsMapper::class,
        'entries' => Fields\EntriesMapper::class,
        'terms' => Fields\TermsMapper::class,
        'users' => Fields\UsersMapper::class,
        'replicator' => Fields\ReplicatorMapper::class,
        'grid' => Fields\GridMapper::class,
        'group' => Fields\GroupMapper::class,
        'dictionary' => Fields\DictionaryMapper::class,
        'yaml' => Fields\PassthroughMapper::class,
    ];

    /** @param class-string<FieldMapper> $mapper */
    public function register(string $fieldtype, string $mapper): void
    {
        $this->mappers[$fieldtype] = $mapper;
    }

    public function for(Field $field): FieldMapper
    {
        return app($this->mappers[$field->type()] ?? Fields\PassthroughMapper::class);
    }

    public function supports(string $fieldtype): bool
    {
        return isset($this->mappers[$fieldtype]);
    }
}
