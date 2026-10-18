<?php

namespace NineteenNinetyFour\Ingest\Runs;

use NineteenNinetyFour\Ingest\IngestException;

class AlreadyRunning extends IngestException
{
    public function __construct(string $message, public ?Run $run = null)
    {
        parent::__construct($message);
    }
}
