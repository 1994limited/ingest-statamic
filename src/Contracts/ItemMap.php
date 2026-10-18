<?php

namespace NineteenNinetyFour\Ingest\Contracts;

use Illuminate\Support\LazyCollection;
use NineteenNinetyFour\Ingest\Map\MapRecord;

/**
 * Which content each feed owns, by the feed item's key.
 */
interface ItemMap
{
    public function find(string $feed, string $site, string $key): ?MapRecord;

    /** The record for a piece of content, whichever feed owns it. */
    public function forContent(string $contentId): ?MapRecord;

    public function put(MapRecord $record): void;

    public function delete(MapRecord $record): void;

    /** Records the run didn't see: items gone from the feed. */
    public function missing(string $feed, string $site, string $runId): LazyCollection;

    public function count(string $feed, ?string $site = null): int;

    /** @return LazyCollection<int, MapRecord> */
    public function all(?string $feed = null): LazyCollection;

    public function forget(string $feed): void;

    /** Write anything held in memory. */
    public function flush(): void;
}
