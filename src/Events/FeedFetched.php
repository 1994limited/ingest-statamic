<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * The feed has been downloaded and read: $run->total items.
 */
class FeedFetched
{
    public function __construct(public Feed $feed, public Run $run) {}
}
