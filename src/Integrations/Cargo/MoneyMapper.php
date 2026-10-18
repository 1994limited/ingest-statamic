<?php

namespace NineteenNinetyFour\Ingest\Integrations\Cargo;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\FieldMapper;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use NineteenNinetyFour\Ingest\Mapping\Transforms;
use Statamic\Fields\Field;

/**
 * Cargo's money fields store whole pence (cents): "12.99" becomes 1299.
 * A feed that already sends pence sets "minor_units: true".
 */
class MoneyMapper implements FieldMapper
{
    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        return self::minor($value, ! empty($mapping['minor_units']));
    }

    public static function minor(mixed $value, bool $alreadyMinor = false): int
    {
        $number = Transforms::number(is_array($value) ? ($value[0] ?? null) : $value);

        return $alreadyMinor ? (int) round($number) : (int) round($number * 100);
    }
}
