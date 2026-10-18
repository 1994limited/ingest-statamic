<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

/**
 * The sync tests again, with feeds, runs and the map in the database.
 */
class DatabaseDriverSyncTest extends SyncTest
{
    protected string $driver = 'database';
}
