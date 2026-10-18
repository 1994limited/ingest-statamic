<?php

namespace NineteenNinetyFour\Ingest\Events;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;

class ItemFailed
{
    public function __construct(public Feed $feed, public Run $run, public array $item, public string $message) {}
}
