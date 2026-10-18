<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * Items have gone from the feed and are about to be unpublished or
 * deleted. Set $cancel to leave them all as they are.
 */
class MissingItemsResolving
{
    public bool $cancel = false;

    /**
     * @param  int  $count  how many items are missing
     */
    public function __construct(public Feed $feed, public Run $run, public int $count, public string $action) {}
}
