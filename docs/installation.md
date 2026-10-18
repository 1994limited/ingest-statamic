# Installation

## Requirements

- PHP 8.3 or later, with the `xmlreader`, `dom`, `fileinfo` and `curl` extensions (all standard).
- Statamic 6.35 or later, on Laravel 12 or 13.
- For scheduled feeds: Laravel's scheduler running every minute (below).
- For large feeds: a queue worker is recommended. Without one, runs happen in the background of the request that started them, or straight away on the command line.
- For the database driver: any database Laravel supports.
- For the Cargo features: Cargo 1.15 or later (before 2.0).

## Install

```bash
composer require 1994/ingest-statamic
```

Ingest appears under **Tools → Ingest** in the Control Panel for anyone with the [Ingest permissions](#permissions). Super admins see it straight away.

Feeds are stored as YAML files in `resources/ingest/feeds` by default, so they're versioned and deployed with the rest of the site. Runtime data (run history, which items each feed owns, downloads in progress) goes in `storage/ingest`. To keep everything in the database instead, see [Storage](configuration.md#storage).

## The scheduler

Scheduled feeds start from Laravel's scheduler, which needs one cron entry on the server:

```bash
* * * * * cd /path/to/your/site && php artisan schedule:run >> /dev/null 2>&1
```

Laravel Forge, Ploi and Laravel Cloud have a setting for this. Ingest registers two scheduled commands itself: `ingest:schedule` every minute (starts feeds that are due) and `ingest:prune` daily (clears old run history). If the scheduler hasn't run for a few minutes, the feeds page and the dashboard widget say so.

To start feeds some other way and leave the scheduler alone, set `'schedule' => false` in `config/ingest.php`.

## The queue

With a queue worker running (`php artisan queue:work`), each run is a chain of queued jobs: one to fetch the feed, one per batch of 100 items, and one to finish. A long feed never ties up a single job, and the Control Panel shows progress as it goes.

Without a queue (the `sync` driver), a run started in the Control Panel happens after the page has been sent, and a run started with `php artisan ingest:run` happens there and then. That's fine for feeds of a few thousand items.

To run feeds on their own queue or connection:

```env
INGEST_QUEUE=feeds
INGEST_QUEUE_CONNECTION=redis
```

## Permissions

| Permission | Lets someone |
|---|---|
| View feeds and runs | See feeds, runs and what each run did |
| Run feeds | Run feeds and dry runs, apply dry runs, cancel runs |
| Create and edit feeds | Create, edit, pause, duplicate and delete feeds; turn on webhooks |
| Undo runs | Undo a run |

Setting up a feed also needs permission to change what it writes to: editing and creating entries in its collection, editing its taxonomy's terms or its global set, or editing and creating users and assigning roles and groups. Super admins have all of these.

## Licensing

Ingest is free to use while you build a site: on your machine, and on development or staging sites that aren't public. Buy a licence on the [Statamic Marketplace](https://statamic.com/addons) before the site goes live. One licence covers one live site.

## Updating

```bash
composer update 1994/ingest-statamic
```

With the database driver, also run `php artisan migrate` after an update if the [changelog](../CHANGELOG.md) mentions new migrations.

## Uninstalling

```bash
composer remove 1994/ingest-statamic
```

Content Ingest imported stays where it is. Delete `resources/ingest` and `storage/ingest` (or the `ingest_*` tables) to remove the feeds and their history.
