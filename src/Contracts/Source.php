<?php

namespace NineteenNinetyFour\Ingest\Contracts;

use NineteenNinetyFour\Ingest\Feeds\Feed;
use NineteenNinetyFour\Ingest\Sources\Payload;

/**
 * Where a feed comes from. A source saves what it fetches into the run's
 * folder, one file per page.
 */
interface Source
{
    public static function handle(): string;

    public static function label(): string;

    /**
     * @param  string  $directory  the run's folder, to write pages into
     * @param  array<string, mixed>  $previous  the last successful run's validators (etag, last_modified)
     */
    public function fetch(Feed $feed, string $directory, array $previous = []): Payload;
}
