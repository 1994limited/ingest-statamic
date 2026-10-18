<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Runs\Undoer;
use Statamic\Console\RunsInPlease;

class UndoRun extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:undo {run : The run\'s id} {--force : Don\'t ask}';

    protected $description = 'Undo a run: remove what it created and put back what it changed';

    public function handle(RunRepository $runs, Undoer $undoer): int
    {
        $run = $runs->find((string) $this->argument('run'));

        if (! $run) {
            $this->components->error('There\'s no run with that id.');

            return self::FAILURE;
        }

        if ($reason = $undoer->canUndo($run)) {
            $this->components->error($reason);

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Undo run {$run->id} of {$run->feed} ({$run->changed()} changes)?")) {
            return self::SUCCESS;
        }

        try {
            $result = $undoer->undo($run);
        } catch (IngestException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Put back {$result['restored']} items.");
        foreach ($result['conflicts'] as $conflict) {
            $this->components->warn($conflict);
        }

        return self::SUCCESS;
    }
}
