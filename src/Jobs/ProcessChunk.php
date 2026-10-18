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

        $more = $runner->chunk($run, $this->chunk);

        // Null: a duplicate delivery, or a run that's over. Nothing to queue.
        if ($more === true) {
            self::dispatch($this->runId, $this->chunk + 1);
        } elseif ($more === false) {
            FinishRun::dispatch($this->runId);
        }
    }
}
