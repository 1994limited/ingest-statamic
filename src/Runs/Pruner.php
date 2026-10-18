<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\Settings;

/**
 * Keeps run history small: item-level detail for the last few runs of
 * each feed (and failed runs for a while), summaries for longer, and
 * personal data (users feeds) only briefly. Dry runs' saved items go after
 * a week.
 */
class Pruner
{
    public function __construct(private RunRepository $runs, private FeedRepository $feeds) {}

    /** @return array{details: int, payloads: int, runs: int} */
    public function prune(): array
    {
        $keep = Settings::int('keep_runs', 20, 1);
        $failedDays = Settings::int('keep_failed_days', 30, 1);
        $personalDays = max(1, (int) config('ingest.history.keep_personal_days', 7));
        $now = CarbonImmutable::now();
        $counts = ['details' => 0, 'payloads' => 0, 'runs' => 0];

        $byFeed = [];
        foreach ($this->runs->all() as $run) {
            $byFeed[$run->feed][] = $run;
        }

        foreach ($byFeed as $runs) {
            usort($runs, fn (Run $a, Run $b) => strcmp($b->id, $a->id));

            foreach ($runs as $i => $run) {
                if ($run->status->active()) {
                    continue;
                }

                $age = $run->finishedAt ?? $run->createdAt ?? $now;
                $failed = in_array($run->status, [RunStatus::Failed, RunStatus::CompletedWithErrors, RunStatus::Aborted], true);
                $personal = $this->runs->items($run, [], 1)['items']->first()?->personal ?? false;

                $expired = $i >= $keep && ! ($failed && $age->gt($now->subDays($failedDays)));
                if ($personal && $age->lt($now->subDays($personalDays))) {
                    $expired = true;
                }

                if ($expired && $this->runs->items($run, [], 1)['total'] > 0) {
                    $this->runs->forgetItems($run);
                    $counts['details']++;
                }

                // Summaries of runs a year old go too.
                if ($age->lt($now->subYear())) {
                    $this->runs->delete($run);
                    $counts['runs']++;
                }
            }
        }

        // Dry runs' items, kept so they can be applied, and anything left behind.
        foreach (File::directories(Ingest::storagePath('payloads')) as $dir) {
            if (File::lastModified($dir) < $now->subDays(7)->getTimestamp()) {
                File::deleteDirectory($dir);
                $counts['payloads']++;
            }
        }

        return $counts;
    }
}
