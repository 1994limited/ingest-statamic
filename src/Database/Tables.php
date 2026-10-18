<?php

namespace NineteenNinetyFour\Ingest\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database driver's tables, on the configured connection.
 */
class Tables
{
    public static function connection(): Connection
    {
        return DB::connection(config('ingest.drivers.database.connection') ?: null);
    }

    public static function name(string $table): string
    {
        return (string) config("ingest.drivers.database.tables.{$table}", 'ingest_'.$table);
    }

    public static function query(string $table): Builder
    {
        return self::connection()->table(self::name($table));
    }

    /** Whether the migrations have run. */
    public static function exist(): bool
    {
        $schema = Schema::connection(config('ingest.drivers.database.connection') ?: null);

        foreach (['feeds', 'runs', 'run_items', 'items'] as $table) {
            if (! $schema->hasTable(self::name($table))) {
                return false;
            }
        }

        return true;
    }
}
