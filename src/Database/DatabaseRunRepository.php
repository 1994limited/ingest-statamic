<?php

namespace NineteenNinetyFour\Ingest\Database;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use NineteenNinetyFour\Ingest\Contracts\RunRepository;
use NineteenNinetyFour\Ingest\Runs\Run;
use NineteenNinetyFour\Ingest\Runs\RunItem;
use NineteenNinetyFour\Ingest\Runs\RunStatus;

class DatabaseRunRepository implements RunRepository
{
    public function find(string $id): ?Run
    {
        $row = Tables::query('runs')->where('id', $id)->first();

        return $row ? Run::fromArray(json_decode($row->data, true)) : null;
    }

    public function save(Run $run): void
    {
        Tables::query('runs')->upsert([[
            'id' => $run->id,
            'feed' => $run->feed,
            'status' => $run->status->value,
            'dry_run' => $run->dryRun,
            'data' => json_encode($run->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $run->createdAt?->toDateTimeString(),
            'finished_at' => $run->finishedAt?->toDateTimeString(),
        ]], ['id'], ['status', 'data', 'finished_at']);
    }

    public function delete(Run $run): void
    {
        Tables::query('run_items')->where('run_id', $run->id)->delete();
        Tables::query('runs')->where('id', $run->id)->delete();
    }

    public function forFeed(string $feed, int $limit = 20, int $offset = 0): Collection
    {
        return Tables::query('runs')->where('feed', $feed)->orderByDesc('id')->offset($offset)->limit($limit)->get()
            ->map(fn ($row) => Run::fromArray(json_decode($row->data, true)));
    }

    public function countForFeed(string $feed): int
    {
        return Tables::query('runs')->where('feed', $feed)->count();
    }

    public function latest(int $limit = 20): Collection
    {
        return Tables::query('runs')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($row) => Run::fromArray(json_decode($row->data, true)));
    }

    public function active(string $feed): ?Run
    {
        $active = array_map(fn (RunStatus $s) => $s->value, array_filter(RunStatus::cases(), fn ($s) => $s->active()));
        $row = Tables::query('runs')->where('feed', $feed)->whereIn('status', $active)->orderByDesc('id')->first();

        return $row ? Run::fromArray(json_decode($row->data, true)) : null;
    }

    public function addItems(Run $run, array $items): void
    {
        foreach (array_chunk($items, 200) as $chunk) {
            Tables::query('run_items')->insert(array_map(fn (RunItem $item) => [
                'run_id' => $run->id,
                'action' => $item->action,
                'key' => mb_substr($item->key, 0, 250),
                'content_id' => $item->contentId,
                'title' => $item->title !== null ? mb_substr($item->title, 0, 250) : null,
                'data' => json_encode($item->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ], $chunk));
        }
    }

    public function items(Run $run, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $query = Tables::query('run_items')->where('run_id', $run->id);

        if ($actions = (array) ($filters['action'] ?? [])) {
            $query->whereIn('action', $actions);
        }

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where(fn ($q) => $q->where('key', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%"));
        }

        $total = (clone $query)->count();
        $items = $query->orderBy('id')->offset($offset)->limit($limit)->get()
            ->map(fn ($row) => RunItem::fromArray(json_decode($row->data, true)));

        return ['items' => $items, 'total' => $total];
    }

    public function allItems(Run $run): LazyCollection
    {
        return Tables::query('run_items')->where('run_id', $run->id)->orderBy('id')->lazyById(500)
            ->map(fn ($row) => RunItem::fromArray(json_decode($row->data, true)));
    }

    public function forgetItems(Run $run): void
    {
        Tables::query('run_items')->where('run_id', $run->id)->delete();
    }

    public function all(): LazyCollection
    {
        return Tables::query('runs')->orderBy('id')->lazyById(200, 'id')
            ->map(fn ($row) => Run::fromArray(json_decode($row->data, true)));
    }
}
