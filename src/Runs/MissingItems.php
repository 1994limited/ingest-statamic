<?php

namespace NineteenNinetyFour\Ingest\Runs;

use Carbon\CarbonImmutable;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\Destinations\GlobalsDestination;
use NineteenNinetyFour\Ingest\Events\MissingItemsResolving;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Ingest;
use Statamic\Contracts\Search\Searchable;
use Statamic\Facades\Search;
use Statamic\StaticCaching\Invalidator;
use Throwable;

/**
 * Items the feed owns that weren't in this run: leave them, unpublish
 * them, or delete them, as the feed says.
 *
 * Guarded: only after a complete fetch (every page, no item limit), never
 * when the feed came back empty (unless allowed), never past the removal
 * threshold (unless forced), and only items this feed owns in its site.
 * Deletes are snapshotted, so undo can put them back.
 */
class MissingItems
{
    public function __construct(
        private ItemMap $map,
        private RunRepository $runs,
        private Destinations $destinations,
    ) {}

    public function resolve(Feed $feed, Run $run): void
    {
        $destination = $this->destinations->for($feed);
        $missing = $feed->sync()['missing'] ?? [];
        $action = (string) ($missing['action'] ?? 'leave');

        if ($destination instanceof GlobalsDestination) {
            return;
        }

        if (empty($run->payload['complete'])) {
            $this->note($run, __('Items missing from the feed were left alone, because this run didn\'t read the whole feed.'));

            return;
        }

        $seen = (new ItemsFile(Ingest::storagePath('payloads/'.$run->id)))->seen();
        $owned = $this->map->all($feed->handle())->filter(fn ($r) => $r->site === $feed->site() && $r->contentType === $destination->contentType());
        $total = 0;
        $gone = [];

        foreach ($owned as $record) {
            $total++;
            if (! isset($seen[$record->key])) {
                $gone[] = $record;
            }
        }

        // Already handled on an earlier run: unpublished stays unpublished
        // unless it's now due to be deleted.
        $days = $missing['delete_after_days'] ?? null;
        $pending = array_values(array_filter($gone, function ($record) use ($action, $days) {
            if ($action === 'leave') {
                return $record->missingSince === null;
            }
            if ($record->state === 'unpublished') {
                return $action === 'delete' && $days !== null && $this->due($record->missingSince, (int) $days);
            }

            return true;
        }));

        if ($pending === []) {
            return;
        }

        if ($action === 'leave') {
            if (! $run->dryRun) {
                foreach ($pending as $record) {
                    $record->missingSince = CarbonImmutable::now()->toIso8601String();
                    $this->map->put($record);
                }
                $this->map->flush();
            }

            return;
        }

        $safety = (array) ($feed->sync()['safety'] ?? []);
        $forced = ! empty($run->options['force']);

        if ($seen === [] && empty($safety['allow_empty']) && ! $forced) {
            $this->abort($run, __('The feed had no items, so nothing was :action. If an empty feed is expected, tick "Allow an empty feed".', ['action' => $this->past($action)]));

            return;
        }

        $max = (float) ($safety['max_removal_percent'] ?? config('ingest.safety.max_removal_percent', 25));
        $share = $total > 0 ? count($gone) / $total * 100 : 0;

        if ($share > $max && ! $forced) {
            $this->abort($run, __(':count of :total items (:share%) are missing from the feed, more than the :max% safety limit, so nothing was :action. Check the feed, then run it with "Ignore the safety limit" if this is right.', [
                'count' => count($gone), 'total' => $total, 'share' => round($share), 'max' => $max, 'action' => $this->past($action),
            ]));

            return;
        }

        $event = new MissingItemsResolving($feed, $run, count($pending), $action);
        event($event);
        if ($event->cancel) {
            $this->note($run, __('A MissingItemsResolving listener left missing items alone.'));

            return;
        }

        $rows = [];
        foreach ($pending as $record) {
            try {
                $row = $this->one($feed, $run, $destination, $record, $action, $days);
            } catch (Throwable $e) {
                report($e);
                $row = new RunItem($run->id, $record->sourceKey, RunItem::FAILED, $record->contentType, $record->contentId, errors: [$e->getMessage()]);
            }

            if ($row) {
                $run->count($row->action);
                $rows[] = $row;
            }

            if (count($rows) >= 200) {
                $this->runs->addItems($run, $rows);
                $rows = [];
            }
        }

        $this->runs->addItems($run, $rows);
        $this->map->flush();
    }

    private function one(Feed $feed, Run $run, mixed $destination, $record, string $action, ?int $days): ?RunItem
    {
        $content = $destination->find($feed, $record->contentId);

        if (! $content) {
            // Deleted on the site already.
            if (! $run->dryRun) {
                $this->map->delete($record);
            }

            return null;
        }

        $title = $destination->title($content);
        $deleteNow = $action === 'delete' && ($days === null || $this->due($record->missingSince, (int) $days));

        if ($deleteNow) {
            $snapshot = ['content' => $destination->snapshot($content), 'map' => $record->toArray()];

            if (! $run->dryRun) {
                $this->forget($content);
                $destination->delete($content);
                $this->map->delete($record);
                (new ItemsFile(Ingest::storagePath('payloads/'.$run->id)))->markChanged($record->contentType, $record->contentId, RunItem::DELETED);
            }

            return new RunItem($run->id, $record->sourceKey, RunItem::DELETED, $record->contentType, $record->contentId, $title, snapshot: $snapshot, personal: $destination->personal());
        }

        // Unpublish (also the first step of "delete after N days").
        if (! $destination->isPublished($content)) {
            if (! $run->dryRun) {
                $record->state = 'unpublished';
                $record->missingSince ??= CarbonImmutable::now()->toIso8601String();
                $this->map->put($record);
            }

            return null;
        }

        if (! $run->dryRun) {
            $destination->setPublished($content, false);
            $destination->save($content);
            $record->state = 'unpublished';
            $record->missingSince ??= CarbonImmutable::now()->toIso8601String();
            $this->map->put($record);
            (new ItemsFile(Ingest::storagePath('payloads/'.$run->id)))->markChanged($record->contentType, $record->contentId, RunItem::UNPUBLISHED);
        }

        return new RunItem($run->id, $record->sourceKey, RunItem::UNPUBLISHED, $record->contentType, $record->contentId, $title, changes: ['published' => [true, false]], personal: $destination->personal());
    }

    /**
     * Take content out of search and the static cache before it goes.
     */
    private function forget(mixed $content): void
    {
        try {
            if ($content instanceof Searchable) {
                Search::deleteFromIndexes($content);
            }
            app(Invalidator::class)->invalidate($content);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function due(?string $since, int $days): bool
    {
        return $since !== null && CarbonImmutable::parse($since)->addDays($days)->isPast();
    }

    private function abort(Run $run, string $message): void
    {
        $run->status = RunStatus::Aborted;
        $run->message = $message;
    }

    private function note(Run $run, string $message): void
    {
        $run->payload['notes'][] = $message;
    }

    private function past(string $action): string
    {
        return $action === 'delete' ? __('deleted') : __('unpublished');
    }
}
