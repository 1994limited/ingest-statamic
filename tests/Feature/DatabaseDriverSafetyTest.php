<?php

namespace NineteenNinetyFour\Ingest\Tests\Feature;

/**
 * The safety, dry run and undo tests again, with the database driver.
 */
class DatabaseDriverSafetyTest extends SafetyTest
{
    protected string $driver = 'database';
}
