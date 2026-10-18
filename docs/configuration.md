# Configuration

## Settings in the Control Panel

**Tools → Addons → Ingest → Settings** (or **Settings** on the Ingest page) has the options an editor might change:

| Setting | What it does |
|---|---|
| Notification emails | Who hears about failed and stopped runs |
| Most to remove in one run | The default [safety check](sync-rules.md#the-safety-check) for feeds that don't set their own |
| Runs to keep in detail | How many runs per feed keep their item-by-item detail |
| Days to keep failed runs | How long runs with failures keep their detail |
| Items per batch | How many items each queued job handles |

Saved settings go in `resources/addons/ingest-statamic.yaml`, so they deploy with the site. Secrets are never stored here: they stay in `.env`.

## config/ingest.php

Publish the config file to change anything else:

```bash
php artisan vendor:publish --tag=ingest-config
```

| Option | Default | |
|---|---|---|
| `driver` | `file` | `file` or `database`. See [Storage](#storage). `INGEST_DRIVER` |
| `chunk_size` | `100` | Items per queued job |
| `queue.connection`, `queue.name` | default | `INGEST_QUEUE_CONNECTION`, `INGEST_QUEUE` |
| `schedule` | `true` | Register the every-minute scheduler and the daily prune |
| `quiet_saves` | `true` | Save items without firing Statamic's events, then update search, the static cache and Git once at the end. Set `false` if other code needs `EntrySaved` for each item |
| `stale_run_minutes` | `30` | A run with no progress for this long is treated as dead |
| `safety.max_removal_percent` | `25` | See [the safety check](sync-rules.md#the-safety-check) |
| `http.timeout`, `http.connect_timeout` | `30`, `10` | Seconds |
| `http.max_redirects` | `3` | |
| `http.max_feed_size` | `200` | Megabytes |
| `http.verify` | `true` | Check HTTPS certificates. `true`, or a CA bundle's path for a local CA. `INGEST_HTTP_VERIFY` |
| `allowed_hosts` | none | Hosts on private networks that feeds may read from. `INGEST_ALLOWED_HOSTS` (comma-separated, `*` wildcards). See [Security](security.md) |
| `server_paths` | `storage/ingest/drops` | Folders a [server file](sources.md#a-file-on-the-server) may be read from |
| `assets.max_size` | `20` | Largest image or file to download, in megabytes |
| `assets.mime_types` | images and PDF | File types downloads may be |
| `history.keep_runs` | `20` | Runs per feed that keep item detail |
| `history.keep_failed_days` | `30` | |
| `history.keep_personal_days` | `7` | Days to keep item detail of [users](destinations.md#users) feeds |
| `notifications.mail` | none | `INGEST_NOTIFY_MAIL` (comma-separated) |
| `notifications.slack_webhook` | none | `INGEST_SLACK_WEBHOOK_URL` |
| `users.roles`, `users.groups` | none | Roles and groups a users feed may give |
| `google.service_account` | none | Path of a Google service account key, for [private sheets](google-sheets.md#private-sheets). `INGEST_GOOGLE_SERVICE_ACCOUNT` |

## Storage

Ingest keeps three things: the **feeds** themselves, the **run history**, and the **ownership map** (which content each feed created, the key it came from, and what it last wrote). There are two drivers.

### Files (the default)

- Feeds: `resources/ingest/feeds/{handle}.yaml`. Commit them; they deploy with the site.
- Runs and the map: `storage/ingest`. Not committed. Keep `storage/ingest` between deploys (Forge, Envoyer and Ploi keep `storage` already).

No database is needed. It suits feeds of up to tens of thousands of items.

### Database

For very large feeds, several servers, or if you'd rather have everything in a database:

```env
INGEST_DRIVER=database
```

```bash
php artisan vendor:publish --tag=ingest-migrations
php artisan migrate
```

This makes four tables: `ingest_feeds`, `ingest_runs`, `ingest_run_items` and `ingest_items`. Feeds are rows in the database, so they don't deploy with the code: set them up on each environment, or copy them across with `ingest:transfer`. `INGEST_DB_CONNECTION` puts the tables on another connection.

### Moving between drivers

```bash
php artisan ingest:transfer --to=database
```

copies feeds, run history and the ownership map from files to the database (`--to=file` goes the other way), then tells you to change `INGEST_DRIVER`. It refuses if the tables don't exist yet, and says how to make them.
