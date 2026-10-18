<?php

namespace NineteenNinetyFour\Ingest\Mapping;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Template;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;
use Throwable;

/**
 * Maps a feed item onto fields.
 *
 * Each field's mapping says where its value comes from and what to do with it:
 *
 *   path        a path in the item (formats.*.sku); "^." reads from the
 *               top-level item inside a grid or replicator row
 *   value       a fixed value
 *   template    "{first_name} {last_name}"
 *   transforms  see Transforms
 *   empty       when the feed has nothing: clear (default), keep, default
 *   default     the value for empty: default
 *   create_only set the field only when the item is created
 *
 * Only mapped fields are touched. Fields not in the mapping are never
 * read, cleared or changed.
 */
class Mapper
{
    public function __construct(
        private Transforms $transforms,
        private FieldMappers $fieldMappers,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $mapping  field handle => settings
     * @param  Collection<string, Field>  $fields  handle => field
     * @param  array<string, mixed>  $item  the item (or row) values are read from
     */
    public function map(array $mapping, Collection $fields, array $item, Context $context): Mapped
    {
        $mapped = new Mapped;

        foreach ($mapping as $handle => $settings) {
            if (! is_array($settings) || ! $this->isMapped($settings)) {
                continue;
            }

            $field = $fields->get($handle);

            if (! $field) {
                $mapped->errors[$handle] = "The blueprint has no field called {$handle}.";

                continue;
            }

            try {
                $result = $this->field($field, $settings, $item, $context);
            } catch (FieldError $e) {
                $mapped->errors[$handle] = $e->getMessage();

                continue;
            } catch (Throwable $e) {
                report($e);
                $mapped->errors[$handle] = $e->getMessage();

                continue;
            }

            if ($result !== Skip::FIELD) {
                $mapped->values[$handle] = $result;
            }
        }

        return $mapped;
    }

    /**
     * One field's value, or Skip::FIELD to leave the field as it is.
     */
    public function field(Field $field, array $settings, array $item, Context $context): mixed
    {
        // Set once, when the item is created; left to the site (or another feed) after that.
        if (! empty($settings['create_only']) && ! $context->isNew) {
            return Skip::FIELD;
        }

        // A group mapped field by field reads the same item.
        if (! empty($settings['fields']) && empty($settings['path'])) {
            return $this->fieldMappers->for($field)->map($item, $field, $settings, $context, $this);
        }

        $value = $this->resolve($settings, $item, $context);

        if (Value::isEmpty($value)) {
            return match ($settings['empty'] ?? 'clear') {
                'keep' => Skip::FIELD,
                'default' => Value::isEmpty($settings['default'] ?? null)
                    ? null
                    : $this->fieldMappers->for($field)->map($settings['default'], $field, $settings, $context, $this),
                default => null,
            };
        }

        return $this->fieldMappers->for($field)->map($value, $field, $settings, $context, $this);
    }

    /**
     * The raw value a mapping points at, after transforms.
     */
    public function resolve(array $settings, array $item, Context $context): mixed
    {
        if (isset($settings['template']) && $settings['template'] !== '') {
            $value = Template::render((string) $settings['template'], $this->scope($settings['template'], $item, $context));
        } elseif (isset($settings['path']) && $settings['path'] !== '') {
            $value = $this->read((string) $settings['path'], $item, $context);
        } elseif (array_key_exists('value', $settings)) {
            $value = $settings['value'];
        } else {
            return null;
        }

        $value = Value::unwrap($value);

        return $this->transforms->applyAll($value, (array) ($settings['transforms'] ?? []), $context);
    }

    public function read(string $path, array $item, Context $context): mixed
    {
        if (str_starts_with($path, '^.') || str_starts_with($path, '^/')) {
            return Path::get($context->item, substr($path, 2));
        }

        return Path::get($item, $path);
    }

    public function isMapped(array $settings): bool
    {
        return (isset($settings['path']) && $settings['path'] !== '')
            || (isset($settings['template']) && $settings['template'] !== '')
            || array_key_exists('value', $settings)
            // Grids and groups map their own fields.
            || ! empty($settings['fields']);
    }

    private function scope(string $template, array $item, Context $context): array
    {
        // Templates inside rows can read the top-level item with ^.path.
        if (! str_contains($template, '{^')) {
            return $item;
        }

        return $item + ['^' => $context->item];
    }
}
