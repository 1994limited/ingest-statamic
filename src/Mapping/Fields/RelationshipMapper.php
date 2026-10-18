<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Html;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;

/**
 * Entries, terms and users fields: each feed value is matched to existing
 * content ("match": id, slug, title or another field's handle) and, for
 * entries and terms, created when "create" is on. A dry run reports what
 * it would create without creating it.
 */
abstract class RelationshipMapper implements FieldMapper
{
    /** @return string|null the stored reference, or null when nothing matches */
    abstract protected function find(string $value, string $match, Field $field, array $mapping, Context $context): ?string;

    /** @return string|null the stored reference of the new item */
    abstract protected function create(string $value, Field $field, array $mapping, Context $context): ?string;

    abstract protected function noun(): string;

    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $values = array_values(array_unique(array_filter(
            array_map(fn ($v) => Html::text(Value::text($v)), Path::isList($value) ? $value : [$value]),
            fn ($v) => $v !== ''
        )));

        $match = (string) ($mapping['match'] ?? 'title');
        $out = [];

        foreach ($values as $one) {
            $reference = $this->find($one, $match, $field, $mapping, $context);

            if ($reference === null && ! empty($mapping['create'])) {
                if ($context->dryRun) {
                    $context->warn("{$field->display()}: would create the {$this->noun()} \"{$one}\".");

                    continue;
                }

                $reference = $this->create($one, $field, $mapping, $context);
            }

            if ($reference === null) {
                $context->warn("{$field->display()}: no {$this->noun()} matches \"{$one}\".");

                continue;
            }

            $out[] = $reference;
        }

        $out = array_values(array_unique($out));

        if ((int) $field->get('max_items') === 1) {
            return $out[0] ?? null;
        }

        return $field->get('max_items') ? array_slice($out, 0, (int) $field->get('max_items')) : $out;
    }
}
