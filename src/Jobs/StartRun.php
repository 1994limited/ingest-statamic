<?php

namespace NineteenNinetyFour\Ingest\Jobs;

use NineteenNinetyFour\Ingest\Runs\Runner;

class StartRun extends RunJob
{
    public function handle(Runner $runner): void
    {
        if (($run = $this->run()) && $runner->fetch($run)) {
            ProcessChunk::dispatch($this->runId, 0);
        }
    }
}
