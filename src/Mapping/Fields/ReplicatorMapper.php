<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldError;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Support\Path;
use NineteenNinetyFour\Ingest\Support\Value;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Replicator rows. The set is fixed ("set: text") or read from each
 * element ("set_path: type", translated through "set_map" when the feed's
 * names differ). Each set's fields are mapped under "sets.{set}.fields",
 * or under "fields" when every set shares them.
 */
class ReplicatorMapper extends RowsMapper
{
    protected function row(Field $field, array $element, array $mapping, Context $context, Mapper $mapper): ?array
    {
        $set = $mapping['set'] ?? null;

        if (isset($mapping['set_path'])) {
            $value = Value::text(Path::get($element, $mapping['set_path']));
            $set = ((array) ($mapping['set_map'] ?? []))[$value] ?? $value;
        }

        if (! $set) {
            throw new FieldError('Choose which set each row uses (a fixed set, or a path in the feed).');
        }

        if (! $this->setExists($field, $set)) {
            $context->warn("{$field->display()}: there's no \"{$set}\" set, so a row was left out.");

            return null;
        }

        $rowMapping = isset($mapping['sets'][$set]) ? (array) $mapping['sets'][$set] : $mapping;

        return ['type' => $set, 'enabled' => true]
            + $this->mapRow($this->rowFields($field, $set), $element, $rowMapping, $context, $mapper, $field->display());
    }

    protected function rowFields(Field $field, ?string $set = null): Fields
    {
        return $field->fieldtype()->fields($set);
    }

    private function setExists(Field $field, string $set): bool
    {
        return collect($field->fieldtype()->flattenedSetsConfig())->has($set);
    }
}
