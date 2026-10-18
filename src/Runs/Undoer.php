<?php

namespace NineteenNinetyFour\Ingest\Runs;

use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Destinations\Destinations;
use NineteenNinetyFour\Ingest\IngestException;
use NineteenNinetyFour\Ingest\Map\MapRecord;
use NineteenNinetyFour\Ingest\Mapping\Context;
use NineteenNinetyFour\Ingest\Support\Value;
use Throwable;

/**
 * Reverses a run: removes what it created, puts back the values it
 * changed, republishes what it unpublished and recreates what it deleted.
 *
 * Anything edited on the site since the run is left alone and listed as a
 * conflict, field by field. Runs are undone newest first. Entries, terms
 * and assets a run created for relationship and asset fields are kept,
 * since other content may use them by now.
 */
class Undoer
{
    /** @var array<string, MapRecord>|null content id => the feed's record */
    private ?array $index = null;

    public function __construct(
        private RunRepository $runs,
        private FeedRepository $feeds,
        private ItemMap $map,
        private Destinations $destinations,
    ) {}

    public function canUndo(Run $run): ?string
    {
        if ($run->dryRun) {
            return __('A dry run changed nothing.');
        }
        if ($run->status === RunStatus::Undone) {
            return __('This run has been undone already.');
        }
        if ($run->status->active()) {
            return __('Wait for the run to finish.');
        }
        if ($run->changed() === 0) {
            return __('This run changed nothing.');
        }
        if ($this->runs->allItems($run)->isEmpty()) {
            return __('This run\'s details have been cleared from the history, so it can\'t be undone.');
        }

        $newer = $this->runs->forFeed($run->feed, 50)->first(fn (Run $r) => $r->id > $run->id && ! $r->dryRun && $r->status !== RunStatus::Undone && $r->changed() > 0);

        if ($newer) {
            return __('Undo the later runs first: runs are undone newest first.');
        }

        return null;
    }

    /**
     * @return array{restored: int, conflicts: string[]}
     */
    public function undo(Run $run): array
    {
        if ($reason = $this->canUndo($run)) {
            throw new IngestException($reason);
        }

        $feed = $this->feeds->find($run->feed) ?? throw new IngestException(__('The feed no longer exists.'));
        $destination = $this->destinations->for($feed);
        $this->index = $this->map->all($feed->handle())->keyBy(fn (MapRecord $r) => $r->contentId)->all();

        $restored = 0;
        $conflicts = [];
        $items = $this->runs->allItems($run)->all();

        foreach (array_reverse($items) as $item) {
            try {
                $result = match ($item->action) {
                    RunItem::CREATED => $this->uncreate($feed, $destination, $item),
                    RunItem::UPDATED, RunItem::CONFLICT => $this->unupdate($feed, $destination, $item),
                    RunItem::UNPUBLISHED => $this->unpublish($feed, $destination, $item),
                    RunItem::DELETED => $this->undelete($feed, $destination, $item),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e);
                $result = [$item->title ?? $item->key].': '.$e->getMessage();
            }

            if ($result === true) {
                $restored++;
            } elseif (is_string($result)) {
                $conflicts[] = $result;
            } elseif (is_array($result)) {
                $conflicts = array_merge($conflicts, $result);
            }
        }

        $this->map->flush();

        $run->status = RunStatus::Undone;
        $run->message = $conflicts === []
            ? __('Undone: :count items put back.', ['count' => $restored])
            : __('Undone: :count items put back; :conflicts left as they are because they changed since the run.', ['count' => $restored, 'conflicts' => count($conflicts)]);
        $run->payload['undo_conflicts'] = array_slice($conflicts, 0, 200);
        $this->runs->save($run);

        return ['restored' => $restored, 'conflicts' => $conflicts];
    }

    private function uncreate($feed, $destination, RunItem $item): bool|string|null
    {
        $content = $item->contentId ? $destination->find($feed, $item->contentId) : null;

        if (! $content) {
            return null;
        }

        // Edited since: keep it.
        $current = $destination->values($feed, $content, array_keys($item->changes));
        foreach ($item->changes as $handle => [, $new]) {
            if (! Value::same($current[$handle] ?? null, $new)) {
                return __(':item was edited after the run created it, so it was kept.', ['item' => $item->title ?? $item->key]);
            }
        }

        $destination->delete($content);
        $this->forgetMap($feed, $item->contentId);

        return true;
    }

    /** @return bool|string[]|null */
    private function unupdate($feed, $destination, RunItem $item): bool|array|null
    {
        $content = $item->contentId ? $destination->find($feed, $item->contentId) : null;

        if (! $content || $item->changes === []) {
            return null;
        }

        $current = $destination->values($feed, $content, array_keys($item->changes));
        $restore = [];
        $conflicts = [];

        foreach ($item->changes as $handle => [$old, $new]) {
            if ($handle === 'published') {
                continue;
            }
            if (Value::same($current[$handle] ?? null, $new)) {
                $restore[$handle] = $old;
            } else {
                $conflicts[] = __(':item › :field changed after the run, so it was kept.', ['item' => $item->title ?? $item->key, 'field' => $handle]);
            }
        }

        // Publication is undone on its own, with its own conflict check.
        $publish = null;
        if (isset($item->changes['published'])) {
            [$was, $became] = $item->changes['published'];
            if ($destination->isPublished($content) === (bool) $became) {
                $publish = (bool) $was;
            } else {
                $conflicts[] = __(':item › published changed after the run, so it was kept.', ['item' => $item->title ?? $item->key]);
            }
        }

        if ($restore !== [] || $publish !== null) {
            if ($restore !== []) {
                $destination->fill($feed, $content, $restore, new Context($feed));
            }
            if ($publish !== null) {
                $destination->setPublished($content, $publish);
            }
            $destination->save($content);

            // The next run compares afresh.
            if ($record = $this->record($item->contentId)) {
                $record->itemHash = null;
                foreach ($restore as $handle => $value) {
                    $record->fieldHashes[$handle] = Value::hash($value);
                }
                $this->map->put($record);
            }
        }

        return $conflicts !== [] ? $conflicts : ($restore !== [] || $publish !== null ? true : null);
    }

    private function unpublish($feed, $destination, RunItem $item): ?bool
    {
        $content = $item->contentId ? $destination->find($feed, $item->contentId) : null;

        if (! $content || $destination->isPublished($content)) {
            return null;
        }

        $destination->setPublished($content, true);
        // Users had their roles and groups taken away: give them back.
        if (method_exists($destination, 'restoreAccess') && isset($item->snapshot['content'])) {
            $destination->restoreAccess($content, $item->snapshot['content']);
        }
        $destination->save($content);

        if ($record = $this->record($item->contentId)) {
            $record->state = null;
            $record->missingSince = null;
            $this->map->put($record);
        }

        return true;
    }

    private function undelete($feed, $destination, RunItem $item): bool|string|null
    {
        $snapshot = $item->snapshot['content'] ?? null;

        if (! $snapshot) {
            return __(':item can\'t be recreated: its snapshot is gone.', ['item' => $item->title ?? $item->key]);
        }

        if ($item->contentId && $destination->find($feed, $item->contentId)) {
            return null;
        }

        $destination->restore($feed, $snapshot);

        if ($map = $item->snapshot['map'] ?? null) {
            $record = MapRecord::fromArray($map);
            $record->itemHash = null;
            $this->map->put($record);
        }

        return true;
    }

    private function forgetMap($feed, string $contentId): void
    {
        $record = $this->record($contentId);

        if ($record && $record->feed === $feed->handle()) {
            $this->map->delete($record);
        }
    }

    private function record(?string $contentId): ?MapRecord
    {
        return $contentId !== null ? ($this->index[$contentId] ?? null) : null;
    }
}
