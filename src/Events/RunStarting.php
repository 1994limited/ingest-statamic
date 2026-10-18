<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

/**
 * Before a run starts. Set $cancel (and a $reason) to stop it.
 */
class RunStarting
{
    public bool $cancel = false;

    public ?string $reason = null;

    public function __construct(public Feed $feed, public Run $run) {}
}
