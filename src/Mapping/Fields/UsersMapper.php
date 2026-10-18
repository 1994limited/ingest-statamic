<?php

namespace NineteenNinetyFour\Ingest\Mapping\Fields;

use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Mapping\Mapper;
use Statamic\Facades\User;
use Statamic\Fields\Field;

/**
 * Users are only ever found (by email, id or name), never created from a
 * relationship field.
 */
class UsersMapper extends RelationshipMapper
{
    protected function noun(): string
    {
        return 'user';
    }

    public function map(mixed $value, Field $field, array $mapping, Context $context, Mapper $mapper): mixed
    {
        $mapping['create'] = false;
        $mapping['match'] ??= 'email';

        return parent::map($value, $field, $mapping, $context, $mapper);
    }

    protected function find(string $value, string $match, Field $field, array $mapping, Context $context): ?string
    {
        return match ($match) {
            'id' => User::find($value)?->id(),
            'email' => User::findByEmail(mb_strtolower($value))?->id(),
            default => User::query()->where($match, $value)->first()?->id(),
        };
    }

    protected function create(string $value, Field $field, array $mapping, Context $context): ?string
    {
        return null;
    }
}
