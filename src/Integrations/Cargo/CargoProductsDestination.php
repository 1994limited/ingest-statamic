<?php

namespace NineteenNinetyFour\Ingest\Integrations\Cargo;

use NineteenNinetyFour\Ingest\Destinations\EntriesDestination;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Mapping\Context;

/**
 * Cargo products: entries in one of Cargo's product collections, with
 * price (simple products) or product_variants (variant products) and stock.
 *
 * Settings, besides the entries ones: tax_class, given to new products
 * that don't map one (Cargo can't check out a product without one).
 */
class CargoProductsDestination extends EntriesDestination
{
    public static function handle(): string
    {
        return 'cargo_products';
    }

    public static function label(): string
    {
        return __('Cargo products');
    }

    public function validate(Feed $feed): void
    {
        if ($reason = Cargo::status()) {
            throw new IngestException($reason);
        }

        parent::validate($feed);

        $collection = (string) ($feed->destination()['collection'] ?? '');
        if (! in_array($collection, Cargo::productCollections(), true)) {
            throw new IngestException("The {$collection} collection isn't one of Cargo's product collections (statamic.cargo.products.collections).");
        }
    }

    public function make(Feed $feed, array $values, Context $context): mixed
    {
        $entry = parent::make($feed, $values, $context);

        if (! array_key_exists('tax_class', $values) && ($taxClass = $feed->destination()['tax_class'] ?? null)) {
            $entry->set('tax_class', $taxClass);
        }

        return $entry;
    }
}
