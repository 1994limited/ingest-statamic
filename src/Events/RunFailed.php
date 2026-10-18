<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * A run stopped with an error: $run->message says why.
 */
class RunFailed
{
    public function __construct(public Feed $feed, public Run $run) {}
}
