<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Preview\Preview;
use Statamic\Console\RunsInPlease;
use Throwable;

class CheckFeed extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:check {feed : The feed\'s handle}';

    protected $description = 'Fetch a feed and show what Ingest reads from it, without saving anything';

    public function handle(FeedRepository $feeds, Preview $preview): int
    {
        $feed = $feeds->find((string) $this->argument('feed'));

        if (! $feed) {
            $this->components->error('There\'s no feed with that handle.');

            return self::FAILURE;
        }

        try {
            $result = $preview->fetch($feed);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Format', $result['format']);
        $this->components->twoColumnDetail('Item path', $result['item_path'] ?: '(the whole document)');
        $this->components->twoColumnDetail('Items in the first page', (string) $result['count']);
        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if ($first = $result['items'][0] ?? null) {
            $this->newLine();
            $this->line('First item:');
            $this->line(json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($result['mapped'] ?? null) {
            $this->newLine();
            $this->line('Mapped values for the first item:');
            $this->line(json_encode($result['mapped'][0] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
