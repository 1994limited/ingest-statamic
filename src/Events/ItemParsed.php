<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Mapping\Context;

/**
 * An item has been read. Change $item, or set $skip to leave it out.
 */
class ItemParsed
{
    public bool $skip = false;

    public function __construct(public Feed $feed, public array $item, public Context $context) {}
}
