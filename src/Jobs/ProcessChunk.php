<?php

namespace NineteenNinetyFour\Ingest\Jobs;

use NineteenNinetyFour\Ingest\Runs\Runner;

class ProcessChunk extends RunJob
{
    public int $timeout = 1800;

    public function __construct(string $runId, public int $chunk)
    {
        parent::__construct($runId);
    }

    public function handle(Runner $runner): void
    {
        $run = $this->run();

        if (! $run) {
            return;
        }

        if ($runner->chunk($run, $this->chunk)) {
            self::dispatch($this->runId, $this->chunk + 1);
        } else {
            FinishRun::dispatch($this->runId);
        }
    }
}
