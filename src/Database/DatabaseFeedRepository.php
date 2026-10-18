<?php

namespace NineteenNinetyFour\Ingest\Database;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Contracts\FeedRepository;
use NineteenNinetyFour\Ingest\Feeds\Feed;

class DatabaseFeedRepository implements FeedRepository
{
    public function all(): Collection
    {
        return Tables::query('feeds')->get()
            ->mapWithKeys(fn ($row) => [$row->handle => Feed::make($row->handle, (array) json_decode($row->data, true))])
            ->sortBy(fn (Feed $feed) => strtolower($feed->title()));
    }

    public function find(string $handle): ?Feed
    {
        $row = Tables::query('feeds')->where('handle', $handle)->first();

        return $row ? Feed::make($row->handle, (array) json_decode($row->data, true)) : null;
    }

    public function save(Feed $feed): void
    {
        $now = now();

        Tables::query('feeds')->upsert([[
            'handle' => $feed->handle(),
            'title' => $feed->title(),
            'data' => json_encode($feed->fileData(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['handle'], ['title', 'data', 'updated_at']);
    }

    public function delete(Feed $feed): void
    {
        Tables::query('feeds')->where('handle', $feed->handle())->delete();
    }
}
