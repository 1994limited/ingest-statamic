<?php

namespace NineteenNinetyFour\Ingest\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Schedule\Scheduler;
use Statamic\Console\RunsInPlease;

class ListFeeds extends Command
{
    use RunsInPlease;

    protected $signature = 'ingest:list';

    protected $description = 'List feeds, their schedules and last runs';

    public function handle(FeedRepository $feeds, RunRepository $runs): int
    {
        $rows = $feeds->all()->map(function ($feed) use ($runs) {
            $last = $runs->forFeed($feed->handle(), 1)->first();

            return [
                $feed->handle(),
                $feed->title(),
                $feed->enabled() ? (Scheduler::expression($feed) ?? 'manual') : 'paused',
                Scheduler::next($feed)?->diffForHumans() ?? '—',
                $last ? $last->status->label().' '.$last->createdAt?->diffForHumans() : 'never',
            ];
        })->values()->all();

        $this->table(['Handle', 'Feed', 'Schedule', 'Next run', 'Last run'], $rows);

        if (! Scheduler::running() && $feeds->all()->contains(fn ($f) => Scheduler::expression($f) !== null)) {
            $this->components->warn('The scheduler hasn\'t run recently, so scheduled feeds won\'t start. Add `php artisan schedule:run` to the server\'s cron.');
        }

        return self::SUCCESS;
    }
}
