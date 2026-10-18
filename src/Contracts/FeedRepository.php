<?php

namespace NineteenNinetyFour\Ingest\Contracts;

use Illuminate\Support\Collection;
use NineteenNinetyFour\Ingest\Feeds\Feed;

interface FeedRepository
{
    /** @return Collection<string, Feed> keyed by handle, sorted by title */
    public function all(): Collection;

    public function find(string $handle): ?Feed;

    public function save(Feed $feed): void;

    public function delete(Feed $feed): void;
}
