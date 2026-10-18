<?php

namespace NineteenNinetyFour\Ingest\Integrations\Cargo;

use Statamic\Contracts\Entries\Entry;

/**
 * A simple product, or one variant option of a product: where a stock
 * feed's price and stock go.
 */
class StockTarget
{
    public function __construct(public Entry $entry, public ?string $option = null) {}

    public function get(string $field): mixed
    {
        if ($this->option === null) {
            return $this->entry->get($field);
        }

        foreach ((array) ($this->entry->get('product_variants')['options'] ?? []) as $option) {
            if ((string) $option['key'] === $this->option) {
                return $option[$field] ?? null;
            }
        }

        return null;
    }

    public function set(string $field, mixed $value): void
    {
        if ($this->option === null) {
            $value === null ? $this->entry->remove($field) : $this->entry->set($field, $value);

            return;
        }

        $variants = (array) $this->entry->get('product_variants');
        foreach ($variants['options'] ?? [] as $i => $option) {
            if ((string) $option['key'] === $this->option) {
                $variants['options'][$i][$field] = $value;
            }
        }

        $this->entry->set('product_variants', $variants);
    }
}
