<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\Runner;
use Statamic\Console\RunsInPlease;

class RunFeed extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:run
        {feed* : The handles of the feeds to run}
        {--dry : See what would change without changing anything}
        {--sync : Run here and now rather than on the queue}
        {--limit= : Only read this many items (nothing is treated as missing)}
        {--force : Read the whole feed even if it hasn\'t changed, and ignore the safety limit}';

    protected $description = 'Run feeds now';

    public function handle(FeedRepository $feeds, Runner $runner): int
    {
        $status = self::SUCCESS;

        foreach ((array) $this->argument('feed') as $handle) {
            $feed = $feeds->find($handle);

            if (! $feed) {
                $this->components->error("There's no feed called {$handle}.");
                $status = self::FAILURE;

                continue;
            }

            try {
                $run = $runner->start($feed, 'cli', (bool) $this->option('dry'), [
                    'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
                    'force' => (bool) $this->option('force'),
                    'sync' => (bool) $this->option('sync'),
                ]);
            } catch (IngestException $e) {
                $this->components->error($feed->title().': '.$e->getMessage());
                $status = self::FAILURE;

                continue;
            }

            $this->report($feed->title(), $run);

            if ($run->status->value === 'failed') {
                $status = self::FAILURE;
            }
        }

        return $status;
    }

    private function report(string $title, Run $run): void
    {
        if ($run->status->active()) {
            $this->components->info("{$title}: queued (run {$run->id}).");

            return;
        }

        $line = sprintf(
            '%s%s: %s. %d created, %d updated, %d unchanged, %d skipped, %d failed, %d unpublished, %d deleted.',
            $title,
            $run->dryRun ? ' (dry run)' : '',
            $run->status->label(),
            ...array_values(array_intersect_key($run->counts, array_flip(['created', 'updated', 'unchanged', 'skipped', 'failed', 'unpublished', 'deleted']))),
        );

        $run->status->successful() ? $this->components->info($line) : $this->components->warn($line);

        if ($run->message) {
            $this->line('  '.$run->message);
        }
    }
}
