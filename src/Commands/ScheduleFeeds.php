<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use Statamic\Console\RunsInPlease;

class ScheduleFeeds extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:schedule';

    protected $description = 'Start feeds that are due (runs every minute from the scheduler)';

    public function handle(Scheduler $scheduler): int
    {
        foreach ($scheduler->tick() as $feed => $result) {
            $this->line("{$feed}: {$result}");
        }

        return self::SUCCESS;
    }
}
