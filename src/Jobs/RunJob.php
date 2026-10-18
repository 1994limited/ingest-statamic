<?php

namespace NineteenNinetyFour\Ingest\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunStatus;
use Throwable;

/**
 * A step of a run. Jobs carry only the run's id; everything else is read
 * fresh. They aren't retried: a failed step marks the run failed, and the
 * run can be started again.
 */
abstract class RunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $runId)
    {
        $this->onConnection(config('ingest.queue.connection'));
        $this->onQueue(config('ingest.queue.name'));
    }

    protected function run(): ?Run
    {
        return app(RunRepository::class)->find($this->runId);
    }

    public function failed(?Throwable $e): void
    {
        $run = $this->run();

        if ($run && $run->status->active()) {
            $run->status = RunStatus::Failed;
            $run->message = $e?->getMessage() ?: __('The queued job failed.');
            $run->finishedAt = now()->toImmutable();
            app(RunRepository::class)->save($run);
        }
    }
}
