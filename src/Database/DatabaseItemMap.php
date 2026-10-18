<?php

namespace NineteenNinetyFour\Ingest\Database;

use Illuminate\Support\LazyCollection;
use NineteenNinetyFour\Ingest\Contracts\ItemMap;
use NineteenNinetyFour\Ingest\Map\MapRecord;

class DatabaseItemMap implements ItemMap
{
    public function find(string $feed, string $site, string $key): ?MapRecord
    {
        $row = Tables::query('items')->where('feed', $feed)->where('site', $site)->where('key', $key)->first();

        return $row ? MapRecord::fromArray((array) $row) : null;
    }

    public function forContent(string $contentId): ?MapRecord
    {
        $row = Tables::query('items')->where('content_id', $contentId)->first();

        return $row ? MapRecord::fromArray((array) $row) : null;
    }

    public function put(MapRecord $record): void
    {
        $row = $record->toArray();
        $row['field_hashes'] = json_encode($record->fieldHashes);

        Tables::query('items')->upsert([$row], ['feed', 'site', 'key'], array_keys(array_diff_key($row, array_flip(['feed', 'site', 'key']))));
    }

    public function delete(MapRecord $record): void
    {
        Tables::query('items')->where('feed', $record->feed)->where('site', $record->site)->where('key', $record->key)->delete();
    }

    public function missing(string $feed, string $site, string $runId): LazyCollection
    {
        return Tables::query('items')->where('feed', $feed)->where('site', $site)
            ->where(fn ($q) => $q->whereNull('last_seen_run_id')->orWhere('last_seen_run_id', '!=', $runId))
            ->lazyById(500)
            ->map(fn ($row) => MapRecord::fromArray((array) $row));
    }

    public function count(string $feed, ?string $site = null): int
    {
        return Tables::query('items')->where('feed', $feed)->when($site, fn ($q) => $q->where('site', $site))->count();
    }

    public function all(?string $feed = null): LazyCollection
    {
        return Tables::query('items')->when($feed, fn ($q) => $q->where('feed', $feed))->lazyById(500)
            ->map(fn ($row) => MapRecord::fromArray((array) $row));
    }

    public function forget(string $feed): void
    {
        Tables::query('items')->where('feed', $feed)->delete();
    }

    public function flush(): void
    {
        // Written straight away.
    }
}
