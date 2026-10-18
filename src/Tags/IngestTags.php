<?php

namespace NineteenNinetyFour\Ingest\Tags;

use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Runs\Run;
use Statamic\Tags\Tags;

/**
 * {{ ingest:feed handle="supplier_catalogue" }}…{{ /ingest:feed }}
 *     A feed's title and when it last synced: "Prices updated 10 minutes ago".
 *
 * {{ ingest:source }}…{{ /ingest:source }}
 *     For the current entry: the feed it comes from, if any.
 *
 * Both give: title, handle, last_synced (a date), last_status, items.
 */
class IngestTags extends Tags
{
    protected static $handle = 'ingest';

    public function feed(): array
    {
        $feed = app(FeedRepository::class)->find((string) $this->params->get(['handle', 'feed']));

        return $feed ? $this->describe($feed) : [];
    }

    public function source(): array
    {
        $id = $this->params->get('id') ?? $this->context->value('id');
        $record = $id ? app(ItemMap::class)->forContent((string) $id) : null;
        $feed = $record ? app(FeedRepository::class)->find($record->feed) : null;

        return $feed ? $this->describe($feed) + ['key' => $record->sourceKey] : [];
    }

    private function describe(Feed $feed): array
    {
        $last = app(RunRepository::class)->forFeed($feed->handle(), 10)
            ->first(fn (Run $run) => ! $run->dryRun && $run->status->successful());

        return [
            'title' => $feed->title(),
            'handle' => $feed->handle(),
            'last_synced' => $last?->finishedAt,
            'last_status' => $last?->status->value,
        ];
    }
}
