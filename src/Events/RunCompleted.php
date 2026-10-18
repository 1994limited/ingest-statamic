<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * A run finished (with or without item errors).
 */
class RunCompleted
{
    public function __construct(public Feed $feed, public Run $run) {}
}
