<?php

namespace NineteenNinetyFour\Ingest\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunItem;

interface RunRepository
{
    public function find(string $id): ?Run;

    public function save(Run $run): void;

    public function delete(Run $run): void;

    /** @return Collection<int, Run> newest first */
    public function forFeed(string $feed, int $limit = 20, int $offset = 0): Collection;

    public function countForFeed(string $feed): int;

    /** @return Collection<int, Run> newest first, every feed */
    public function latest(int $limit = 20): Collection;

    /** The feed's run that hasn't finished, if any. */
    public function active(string $feed): ?Run;

    /** @param RunItem[] $items */
    public function addItems(Run $run, array $items): void;

    /**
     * @param  array{action?: string|string[], search?: string}  $filters
     * @return array{items: Collection<int, RunItem>, total: int}
     */
    public function items(Run $run, array $filters = [], int $limit = 50, int $offset = 0): array;

    /** @return LazyCollection<int, RunItem> in the order they were written */
    public function allItems(Run $run): LazyCollection;

    /** Remove item detail (keeping the run's summary). */
    public function forgetItems(Run $run): void;

    /** @return LazyCollection<int, Run> */
    public function all(): LazyCollection;
}
