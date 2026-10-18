<?php

namespace NineteenNinetyFour\Ingest\Mapping;

/**
 * The result of mapping one item: values to save, fields that failed, and
 * fields to leave alone because the feed had nothing for them.
 */
class Mapped
{
    /**
     * @param  array<string, mixed>  $values  field => value to save (null clears it)
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(
        public array $values = [],
        public array $errors = [],
    ) {}
}
