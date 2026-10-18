<?php

namespace NineteenNinetyFour\Ingest\Hooks;

use NineteenNinetyFour\Ingest\Mapping\Context;

/**
 * PHP for one feed. Register a hook in a service provider and pick it in
 * the feed's settings:
 *
 *     Ingest::hook('supplier', SupplierHook::class);
 *
 * Override what you need. Hooks are code in your app: nothing in the
 * Control Panel can run PHP.
 */
abstract class FeedHook
{
    /**
     * Change an item as it's read, or return null to skip it.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function item(array $item, Context $context): ?array
    {
        return $item;
    }

    /**
     * Change the values about to be saved.
     *
     * @param  array<string, mixed>  $values  field => value
     * @return array<string, mixed>
     */
    public function beforeSave(mixed $content, array $values, Context $context): array
    {
        return $values;
    }

    /**
     * After an item is saved (not on dry runs).
     */
    public function afterSave(mixed $content, Context $context): void {}
}
