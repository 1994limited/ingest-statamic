<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Runs\Pruner;
use Statamic\Console\RunsInPlease;

class PruneRuns extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:prune';

    protected $description = 'Clear old run details (runs daily on its own)';

    public function handle(Pruner $pruner): int
    {
        $counts = $pruner->prune();

        $this->components->info("Cleared the details of {$counts['details']} runs, {$counts['payloads']} saved payloads and {$counts['runs']} old runs.");

        return self::SUCCESS;
    }
}
