<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Database\DatabaseFeedRepository;
use NineteenNinetyFour\Ingest\Database\DatabaseItemMap;
use NineteenNinetyFour\Ingest\Database\DatabaseRunRepository;
use NineteenNinetyFour\Ingest\Database\Tables;
use NineteenNinetyFour\Ingest\Feeds\FileFeedRepository;
use NineteenNinetyFour\Ingest\Map\FileItemMap;
use NineteenNinetyFour\Ingest\Runs\FileRunRepository;
use Statamic\Console\RunsInPlease;

class TransferData extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:transfer
        {--from= : file or database (default: the driver in use)}
        {--to= : file or database}
        {--force : Copy even if the destination already has feeds}';

    protected $description = 'Copy feeds, run history and the ownership map from one storage driver to the other';

    public function handle(): int
    {
        $from = (string) ($this->option('from') ?: config('ingest.driver', 'file'));
        $to = (string) ($this->option('to') ?: ($from === 'file' ? 'database' : 'file'));

        foreach ([$from, $to] as $driver) {
            if (! in_array($driver, ['file', 'database'], true)) {
                $this->components->error("Unknown driver [{$driver}]. Use file or database.");

                return self::FAILURE;
            }
        }

        if ($from === $to) {
            $this->components->error('Choose two different drivers.');

            return self::FAILURE;
        }

        if (in_array('database', [$from, $to], true) && ! Tables::exist()) {
            $this->components->error('The database tables don\'t exist yet. Run:');
            $this->line('  php artisan vendor:publish --tag=ingest-migrations');
            $this->line('  php artisan migrate');

            return self::FAILURE;
        }

        [$source, $target] = [$this->repositories($from), $this->repositories($to)];

        if ($target['feeds']->all()->isNotEmpty() && ! $this->option('force')) {
            $this->components->error("The {$to} driver already has feeds. Use --force to copy over them.");

            return self::FAILURE;
        }

        $feeds = $source['feeds']->all();
        foreach ($feeds as $feed) {
            $target['feeds']->save($feed);
        }
        $this->components->info("Copied {$feeds->count()} feeds.");

        $runs = 0;
        foreach ($source['runs']->all() as $run) {
            $target['runs']->save($run);
            $target['runs']->addItems($run, $source['runs']->allItems($run)->all());
            $runs++;
        }
        $this->components->info("Copied {$runs} runs.");

        $records = 0;
        foreach ($source['map']->all() as $record) {
            $target['map']->put($record);
            if (++$records % 500 === 0) {
                $target['map']->flush();
            }
        }
        $target['map']->flush();
        $this->components->info("Copied {$records} ownership records.");

        $this->newLine();
        $this->line("Now set INGEST_DRIVER={$to} in .env (and run php artisan config:cache if config is cached).");

        return self::SUCCESS;
    }

    private function repositories(string $driver): array
    {
        if ($driver === 'database') {
            return ['feeds' => new DatabaseFeedRepository, 'runs' => new DatabaseRunRepository, 'map' => new DatabaseItemMap];
        }

        $storage = rtrim((string) config('ingest.drivers.file.storage', storage_path('ingest')), '/');

        return [
            'feeds' => new FileFeedRepository((string) config('ingest.drivers.file.feeds', resource_path('ingest/feeds'))),
            'runs' => new FileRunRepository($storage.'/runs'),
            'map' => new FileItemMap($storage.'/map'),
        ];
    }
}
