<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ingest.drivers.database.connection') ?: null;
    }

    public function up(): void
    {
        $tables = config('ingest.drivers.database.tables');

        Schema::create($tables['feeds'] ?? 'ingest_feeds', function (Blueprint $table) {
            $table->string('handle')->primary();
            $table->string('title')->nullable();
            $table->longText('data');
            $table->timestamps();
        });

        Schema::create($tables['runs'] ?? 'ingest_runs', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('feed')->index();
            $table->string('status', 32)->index();
            $table->boolean('dry_run')->default(false);
            $table->longText('data');
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
        });

        Schema::create($tables['run_items'] ?? 'ingest_run_items', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 26)->index();
            $table->string('action', 16);
            $table->string('key');
            $table->string('content_id')->nullable();
            $table->string('title')->nullable();
            $table->longText('data');
            $table->index(['run_id', 'action']);
        });

        Schema::create($tables['items'] ?? 'ingest_items', function (Blueprint $table) {
            $table->id();
            $table->string('feed');
            $table->string('site');
            $table->string('key', 40);
            $table->string('source_key')->nullable();
            $table->string('content_type', 32);
            $table->string('content_id')->index();
            $table->string('item_hash', 40)->nullable();
            $table->longText('field_hashes')->nullable();
            $table->string('first_run_id', 26)->nullable();
            $table->string('last_seen_run_id', 26)->nullable();
            $table->string('missing_since')->nullable();
            $table->string('state', 16)->nullable();
            $table->unique(['feed', 'site', 'key']);
            $table->index(['feed', 'site', 'last_seen_run_id']);
        });
    }

    public function down(): void
    {
        $tables = config('ingest.drivers.database.tables');

        foreach (['feeds', 'runs', 'run_items', 'items'] as $name) {
            Schema::dropIfExists($tables[$name] ?? 'ingest_'.$name);
        }
    }
};
