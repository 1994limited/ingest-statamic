<?php

namespace NineteenNinetyFour\Ingest\Jobs;

use NineteenNinetyFour\Ingest\Runs\Runner;

class FinishRun extends RunJob
{
    public function handle(Runner $runner): void
    {
        if ($run = $this->run()) {
            $runner->finish($run);
        }
    }
}
