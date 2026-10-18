<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * A run finished, but the safety check skipped the missing-items step.
 */
class RunAborted
{
    public function __construct(public Feed $feed, public Run $run) {}
}
