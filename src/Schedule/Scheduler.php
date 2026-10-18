<?php

namespace NineteenNinetyFour\Ingest\Schedule;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Notifications\Notifier;
use NineteenNinetyFour\Ingest\Runs\AlreadyRunning;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\Runner;
use Throwable;

/**
 * Starts feeds when they're due. Runs every minute from Laravel's
 * scheduler (`php artisan schedule:run` in the server's cron).
 *
 * A feed's schedule:
 *   frequency: manual | minutes (every: 15) | hourly (minute: 5)
 *              | daily (time: "06:30") | weekly (day: 1, time: "06:30")
 *              | cron (expression: "0 *\/2 * * *")
 */
class Scheduler
{
    public const HEARTBEAT = 'ingest:scheduler:heartbeat';

    public function __construct(
        private FeedRepository $feeds,
        private RunRepository $runs,
        private Runner $runner,
        private Notifier $notifier,
    ) {}

    /**
     * @return array<string, string> feed handle => what happened
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        Cache::forever(self::HEARTBEAT, $now->toIso8601String());

        $results = [];

        foreach ($this->feeds->all() as $feed) {
            if (! $feed->enabled()) {
                continue;
            }

            $this->checkStale($feed);

            $expression = self::expression($feed);
            if ($expression === null || ! (new CronExpression($expression))->isDue($now->setTimezone(config('app.timezone', 'UTC')))) {
                continue;
            }

            try {
                $this->runner->start($feed, 'schedule');
                $results[$feed->handle()] = 'started';
            } catch (AlreadyRunning) {
                $results[$feed->handle()] = 'already running';
            } catch (Throwable $e) {
                report($e);
                $results[$feed->handle()] = $e->getMessage();
            }
        }

        return $results;
    }

    public static function expression(Feed $feed): ?string
    {
        $s = (array) $feed->get('schedule', []);
        [$hour, $minute] = array_map('intval', array_pad(explode(':', (string) ($s['time'] ?? '06:00')), 2, 0));

        return match ($s['frequency'] ?? 'manual') {
            'minutes' => '*/'.max(1, min(59, (int) ($s['every'] ?? 15))).' * * * *',
            'hourly' => ((int) ($s['minute'] ?? 0) % 60).' * * * *',
            'daily' => "{$minute} {$hour} * * *",
            'weekly' => "{$minute} {$hour} * * ".((int) ($s['day'] ?? 1) % 7),
            'cron' => CronExpression::isValidExpression((string) ($s['expression'] ?? '')) ? (string) $s['expression'] : null,
            default => null,
        };
    }

    public static function next(Feed $feed): ?CarbonImmutable
    {
        $expression = self::expression($feed);

        if (! $feed->enabled() || $expression === null) {
            return null;
        }

        return CarbonImmutable::instance((new CronExpression($expression))->getNextRunDate('now', 0, false, config('app.timezone', 'UTC')));
    }

    /** Whether the scheduler has run in the last few minutes. */
    public static function running(): bool
    {
        $last = Cache::get(self::HEARTBEAT);

        return $last !== null && CarbonImmutable::parse($last)->gt(CarbonImmutable::now()->subMinutes(5));
    }

    private function checkStale(Feed $feed): void
    {
        $hours = (int) ($feed->get('notify.stale_after_hours') ?? 0);

        if ($hours <= 0) {
            return;
        }

        $last = $this->runs->forFeed($feed->handle(), 30)->first(fn (Run $r) => ! $r->dryRun && $r->status->successful());
        $since = $last?->finishedAt ?? $this->runs->forFeed($feed->handle(), 1)->last()?->createdAt;

        if ($since && $since->lt(CarbonImmutable::now()->subHours($hours))) {
            $this->notifier->stale($feed, $last, $hours);
        }
    }
}
