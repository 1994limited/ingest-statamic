<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Where feeds, run history and the record of which items each feed owns
    | are kept: "file" (YAML and JSON files, no database needed) or
    | "database". Move between them with `php please ingest:transfer`.
    |
    */

    'driver' => env('INGEST_DRIVER', 'file'),

    'drivers' => [
        'file' => [
            // Feed definitions: deployed with the site, like blueprints.
            'feeds' => resource_path('ingest/feeds'),
            // Runs, payloads and the ownership map: written at runtime.
            'storage' => storage_path('ingest'),
        ],
        'database' => [
            'connection' => env('INGEST_DB_CONNECTION'),
            'tables' => [
                'feeds' => 'ingest_feeds',
                'runs' => 'ingest_runs',
                'run_items' => 'ingest_run_items',
                'items' => 'ingest_items',
            ],
            // Payloads and undo snapshots are files even with the database driver.
            'storage' => storage_path('ingest'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Running
    |--------------------------------------------------------------------------
    */

    // Items handled per queued job.
    'chunk_size' => 100,

    // The queue connection and queue for runs. Null uses the defaults.
    'queue' => [
        'connection' => env('INGEST_QUEUE_CONNECTION'),
        'name' => env('INGEST_QUEUE'),
    ],

    // Register the every-minute scheduler that starts feeds when they're due.
    'schedule' => true,

    // Save items without firing Statamic's events (faster). Search indexes,
    // the static cache and Git are brought up to date once at the end of a run.
    'quiet_saves' => true,

    // A run that hasn't reported progress for this many minutes is treated as dead.
    'stale_run_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    'safety' => [
        // Skip the "missing items" step when a run would remove more than this
        // share of a feed's items. Each feed can override it.
        'max_removal_percent' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fetching
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'max_redirects' => 3,
        // Largest feed file, in megabytes.
        'max_feed_size' => 200,
        'user_agent' => 'Ingest for Statamic',
    ],

    // Hosts allowed even though they resolve to private or internal
    // addresses (an intranet ERP, say). Everything else on a private
    // network is refused.
    'allowed_hosts' => array_filter(explode(',', (string) env('INGEST_ALLOWED_HOSTS', ''))),

    // Folders a "server path" feed may read from.
    'server_paths' => [
        storage_path('ingest/drops'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    */

    'assets' => [
        // Largest file to download, in megabytes.
        'max_size' => 20,
        'mime_types' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
            'application/pdf',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Run history
    |--------------------------------------------------------------------------
    */

    'history' => [
        // Item-level detail is kept for this many runs per feed.
        'keep_runs' => 20,
        // Runs with failures keep their detail for this many days.
        'keep_failed_days' => 30,
        // Personal data (users) is kept for this many days.
        'keep_personal_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Also editable in the Control Panel (Ingest → Settings). The Slack
    | webhook URL is a secret, so it only lives here.
    |
    */

    'notifications' => [
        'mail' => array_filter(explode(',', (string) env('INGEST_NOTIFY_MAIL', ''))),
        'slack_webhook' => env('INGEST_SLACK_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    'users' => [
        // Roles and groups a feed may assign. Super admin can never be set.
        'roles' => [],
        'groups' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sheets (private sheets)
    |--------------------------------------------------------------------------
    |
    | Path to a Google service account JSON key. Share each sheet with the
    | service account's email address. Public sheets need nothing here.
    |
    */

    'google' => [
        'service_account' => env('INGEST_GOOGLE_SERVICE_ACCOUNT'),
    ],

];
