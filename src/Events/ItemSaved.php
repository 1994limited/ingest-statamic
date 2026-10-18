<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Mapping\Context;

class ItemSaved
{
    /**
     * @param  string  $action  created or updated
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function __construct(public Feed $feed, public mixed $content, public string $action, public array $changes, public Context $context) {}
}
