<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Destinations\GlobalsDestination;
use NineteenNinetyFour\Ingest\Events\FeedFetched;
use NineteenNinetyFour\Ingest\Events\RunFailed;
use NineteenNinetyFour\Ingest\Events\RunStarting;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Formats\Formats;
use NineteenNinetyFour\Ingest\Ingest;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Jobs\StartRun;
use NineteenNinetyFour\Ingest\Sources\Sources;
use Throwable;

/**
 * Runs feeds: fetch, then chunks of items one after another, then the
 * missing-items step and the finish. Each step is a queued job that
 * queues the next, so chunks never run at the same time (relationships
 * created by one item are there for the next) and a long feed never
 * holds one job open. Without a queue worker the same steps run in turn.
 */
class Runner
{
    public function __construct(
        private RunRepository $runs,
        private FeedRepository $feeds,
        private Sources $sources,
        private Formats $formats,
        private Destinations $destinations,
        private ItemProcessor $processor,
        private MissingItems $missing,
        private Finisher $finisher,
    ) {}

    /**
     * Queue a run (or run it now, without a queue worker).
     *
     * @param  array<string, mixed>  $options  limit, force, apply (a dry run's id), sync
     */
    public function start(Feed $feed, string $trigger = 'manual', bool $dryRun = false, array $options = [], int|string|null $user = null): Run
    {
        if (! $feed->enabled() && in_array($trigger, ['schedule', 'webhook', 'chain'], true)) {
            throw new IngestException(__('The :feed feed is paused.', ['feed' => $feed->title()]));
        }

        $lock = Cache::lock('ingest:start:'.$feed->handle(), 10);

        if (! $lock->get()) {
            throw new AlreadyRunning(__('The :feed feed is already starting.', ['feed' => $feed->title()]));
        }

        try {
            $this->reapStale($feed);

            if ($active = $this->runs->active($feed->handle())) {
                throw new AlreadyRunning(__('The :feed feed is already running.', ['feed' => $feed->title()]), $active);
            }

            $run = Run::make($feed->handle(), $trigger, $dryRun, array_filter($options, fn ($v) => $v !== null && $v !== false));
            $run->user = $user !== null ? (string) $user : null;
            $run->heartbeatAt = CarbonImmutable::now();

            $event = new RunStarting($feed, $run);
            event($event);

            if ($event->cancel) {
                $run->status = RunStatus::Cancelled;
                $run->message = $event->reason ?? __('Cancelled by a RunStarting listener.');
                $run->finishedAt = CarbonImmutable::now();
            }

            $this->runs->save($run);
        } finally {
            $lock->release();
        }

        if ($run->status === RunStatus::Queued) {
            $this->dispatch($run, ! empty($options['sync']));
        }

        // Run already, without a queue worker: return how it went.
        return $this->runs->find($run->id) ?? $run;
    }

    /**
     * Without a queue worker the run happens after the response is sent
     * (or straight away on the command line).
     */
    private function dispatch(Run $run, bool $sync): void
    {
        $connection = config('ingest.queue.connection') ?? config('queue.default');

        if ($sync || config("queue.connections.{$connection}.driver") === 'sync') {
            if ($sync || app()->runningInConsole()) {
                $this->runNow($run);
            } else {
                dispatch(fn () => app(Runner::class)->runNow(app(RunRepository::class)->find($run->id)))->afterResponse();
            }

            return;
        }

        StartRun::dispatch($run->id);
    }

    /**
     * Every step, one after another, in this process.
     */
    public function runNow(Run $run): Run
    {
        @set_time_limit(0);

        if (! $this->fetch($run)) {
            return $this->runs->find($run->id) ?? $run;
        }

        for ($chunk = 0; $this->chunk($run, $chunk); $chunk++) {
            $run = $this->runs->find($run->id) ?? $run;
        }

        $this->finish($run);

        return $this->runs->find($run->id) ?? $run;
    }

    /**
     * Fetch the feed and write its items out. Returns false when the run
     * is over already (unchanged feed, failure, cancelled).
     */
    public function fetch(Run $run): bool
    {
        $feed = $this->feed($run);

        return $this->guard($run, $feed, function () use ($run, $feed) {
            $run->status = RunStatus::Fetching;
            $run->startedAt ??= CarbonImmutable::now();
            $this->beat($run);

            $destination = $this->destinations->for($feed);
            $destination->validate($feed);

            if ($feed->key() === [] && ! $destination instanceof GlobalsDestination) {
                throw new IngestException(__('Choose which feed value identifies an item (the key) before running.'));
            }

            $directory = Ingest::storagePath('payloads/'.$run->id);
            $items = new ItemsFile($directory);

            if ($apply = $run->options['apply'] ?? null) {
                $this->copyDryRun((string) $apply, $run, $directory);
            } else {
                File::ensureDirectoryExists($directory);
                $previous = $this->lastSuccessful($feed)?->payload['validators'] ?? [];
                // A dry run or a forced run always reads the whole feed.
                if ($run->dryRun || ! empty($run->options['force'])) {
                    $previous = [];
                }

                $payload = $this->sources->make((string) ($feed->source()['type'] ?? 'url'))->fetch($feed, $directory, $previous);

                if ($payload->notModified || $this->sameFile($payload->validators, $previous)) {
                    $run->payload = ['validators' => $previous, 'unchanged' => true];
                    $run->status = RunStatus::Completed;
                    $run->message = __('The feed hasn\'t changed since the last run.');
                    $run->finishedAt = CarbonImmutable::now();
                    $this->runs->save($run);
                    File::deleteDirectory($directory);
                    $this->finisher->afterRun($feed, $run);

                    return false;
                }

                $format = $this->format($feed, $directory, $payload->pages[0] ?? null);
                $reader = $this->formats->make($format);
                $options = $feed->format();
                $options['item_path'] = ($options['item_path'] ?? null) ?: $reader->defaultItemPath();

                $stream = (function () use ($payload, $directory, $reader, $options) {
                    foreach ($payload->pages as $page) {
                        yield from $reader->items($directory.'/'.$page['path'], $options);
                    }
                })();

                $limit = isset($run->options['limit']) ? (int) $run->options['limit'] : ($feed->sync()['limit'] ?? null);
                $fixedKey = $destination instanceof GlobalsDestination ? GlobalsDestination::KEY : null;
                $run->total = $items->write($stream, $feed->key(), $this->chunkSize(), $limit !== null ? (int) $limit : null, $fixedKey);

                $run->payload = [
                    'format' => $format,
                    'pages' => count($payload->pages),
                    'validators' => $payload->validators,
                    'base_url' => $payload->baseUrl,
                    'warnings' => $payload->warnings,
                    // A run cut short (page limit, item limit) never treats anything as missing.
                    'complete' => empty($payload->validators['incomplete']) && $limit === null,
                ];

                // The fetched pages aren't needed once items are written.
                foreach ($payload->pages as $page) {
                    File::delete($directory.'/'.$page['path']);
                }
            }

            $run->status = RunStatus::Processing;
            $this->beat($run);
            event(new FeedFetched($feed, $run));

            return true;
        });
    }

    /**
     * Process one chunk. Returns whether there's another.
     */
    public function chunk(Run $run, int $index): bool
    {
        $run = $this->runs->find($run->id) ?? $run;

        if ($run->status === RunStatus::Cancelling) {
            $this->cancelled($run);

            return false;
        }

        if ($run->status !== RunStatus::Processing) {
            return false;
        }

        $feed = $this->feed($run);
        $items = new ItemsFile(Ingest::storagePath('payloads/'.$run->id));

        return (bool) $this->guard($run, $feed, function () use ($run, $feed, $items, $index) {
            $rows = $this->processor->chunk($feed, $run, $items, $index);
            $this->runs->addItems($run, $rows);
            $this->beat($run);

            return $index + 1 < $items->chunks();
        });
    }

    public function finish(Run $run): void
    {
        $run = $this->runs->find($run->id) ?? $run;

        if ($run->status === RunStatus::Cancelling) {
            $this->cancelled($run);

            return;
        }

        if ($run->status !== RunStatus::Processing) {
            return;
        }

        $feed = $this->feed($run);

        $this->guard($run, $feed, function () use ($run, $feed) {
            $run->status = RunStatus::Finalising;
            $this->beat($run);

            $this->missing->resolve($feed, $run);
            $this->finisher->finish($feed, $run);

            return true;
        });
    }

    public function cancel(Run $run): void
    {
        if ($run->status === RunStatus::Queued) {
            $this->cancelled($run);

            return;
        }

        if ($run->status->active()) {
            $run->status = RunStatus::Cancelling;
            $this->runs->save($run);
        }
    }

    public function chunkSize(): int
    {
        return max(1, (int) config('ingest.chunk_size', 100));
    }

    /**
     * A run that stopped reporting progress (a worker killed mid-run) is
     * marked failed, so the feed can run again.
     */
    public function reapStale(Feed $feed): void
    {
        $run = $this->runs->active($feed->handle());
        $minutes = (int) config('ingest.stale_run_minutes', 30);

        if ($run && ($run->heartbeatAt ?? $run->createdAt)?->lt(CarbonImmutable::now()->subMinutes($minutes))) {
            $run->status = RunStatus::Failed;
            $run->message = __('The run stopped responding (no progress for :minutes minutes). Is the queue worker running?', ['minutes' => $minutes]);
            $run->finishedAt = CarbonImmutable::now();
            $this->runs->save($run);
        }
    }

    private function cancelled(Run $run): void
    {
        $run->status = RunStatus::Cancelled;
        $run->message ??= __('Cancelled. Items processed before cancelling were kept; undo the run to reverse them.');
        $run->finishedAt = CarbonImmutable::now();
        $this->runs->save($run);

        if ($feed = $this->feeds->find($run->feed)) {
            $this->finisher->settle($feed, $run);
            $this->finisher->afterRun($feed, $run);
        }
    }

    /**
     * Run a step, marking the run failed if it throws.
     */
    private function guard(Run $run, Feed $feed, callable $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $e) {
            if (! $e instanceof IngestException) {
                report($e);
            }

            $run->status = RunStatus::Failed;
            $run->message = $e->getMessage();
            $run->finishedAt = CarbonImmutable::now();
            $this->runs->save($run);

            event(new RunFailed($feed, $run));
            $this->finisher->settle($feed, $run);
            $this->finisher->afterRun($feed, $run);

            return false;
        }
    }

    private function beat(Run $run): void
    {
        $run->heartbeatAt = CarbonImmutable::now();
        $this->runs->save($run);
    }

    private function feed(Run $run): Feed
    {
        return $this->feeds->find($run->feed) ?? throw new IngestException(__('The :feed feed no longer exists.', ['feed' => $run->feed]));
    }

    private function format(Feed $feed, string $directory, ?array $page): string
    {
        $type = (string) ($feed->format()['type'] ?? 'auto');

        if ($type !== 'auto') {
            return $type;
        }

        if (! $page) {
            throw new IngestException(__('The source returned nothing.'));
        }

        return $this->formats->detect($directory.'/'.$page['path'], $page['content_type'] ?? null, $page['extension'] ?? null);
    }

    private function sameFile(array $validators, array $previous): bool
    {
        return isset($validators['file_hash'], $previous['file_hash']) && $validators['file_hash'] === $previous['file_hash'];
    }

    private function lastSuccessful(Feed $feed): ?Run
    {
        return $this->runs->forFeed($feed->handle(), 10)
            ->first(fn (Run $r) => ! $r->dryRun && ($r->status->successful() || $r->status === RunStatus::Aborted));
    }

    /**
     * Apply a dry run: the same items it previewed, not a fresh fetch.
     */
    private function copyDryRun(string $id, Run $run, string $directory): void
    {
        $dry = $this->runs->find($id);
        $source = Ingest::storagePath('payloads/'.$id);

        if (! $dry || ! $dry->dryRun || $dry->feed !== $run->feed || ! is_file($source.'/items.ndjson')) {
            throw new IngestException(__('That dry run\'s items aren\'t available any more. Run the feed again.'));
        }

        File::ensureDirectoryExists($directory);
        File::copy($source.'/items.ndjson', $directory.'/items.ndjson');
        File::copy($source.'/chunks.json', $directory.'/chunks.json');

        $run->total = $dry->total;
        $run->payload = $dry->payload;
        $run->payload['applied_from'] = $id;
    }
}
