<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Mapping\Context;

/**
 * About to save an item. Change $values, or set $cancel to leave it as it is.
 * $content is null when the item is new.
 */
class ItemSaving
{
    public bool $cancel = false;

    public function __construct(public Feed $feed, public mixed $content, public array $values, public Context $context) {}
}
