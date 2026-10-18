# Running and scheduling

## In the Control Panel

**Tools → Ingest** lists every feed with its schedule, last run and next run. **Run** and **Dry run** start one. A feed's page has **Run with options**: a limit on the number of items, and **Read the whole feed even if unchanged, and ignore the safety limit**.

A feed runs one run at a time. Starting it while it's running opens the running one. A run that stops reporting progress for 30 minutes (a worker killed mid-run) is marked failed so the feed can run again.

## Schedules

| Schedule | YAML |
|---|---|
| Only when started | `frequency: manual` |
| Every few minutes | `frequency: minutes`, `every: 15` |
| Hourly | `frequency: hourly`, `minute: 5` |
| Daily | `frequency: daily`, `time: '06:30'` |
| Weekly | `frequency: weekly`, `day: 1` (Monday), `time: '06:30'` |
| A cron expression | `frequency: cron`, `expression: '0 */2 * * *'` |

Times are in the app's timezone. Scheduled feeds need [the scheduler](installation.md#the-scheduler). Paused feeds (**Feed is on** unticked) don't run on a schedule, by webhook or from another feed, but can still be run by hand.

## Commands

```bash
php please ingest:run supplier stock          # queue runs (or run them here without a queue)
php please ingest:run supplier --dry          # see what would change
php please ingest:run supplier --sync         # run here and now, whatever the queue
php please ingest:run supplier --limit=20     # only the first 20 items
php please ingest:run supplier --force        # read the feed even if unchanged; ignore the safety limit
php please ingest:check supplier              # fetch and show what Ingest reads, without saving
php please ingest:list                        # feeds, schedules, last runs
php please ingest:undo 01jz…                  # undo a run
php please ingest:prune                       # clear old run detail (runs daily)
php please ingest:transfer --to=database      # move to the database driver
```

`php artisan` works too.

## The webhook

Another system can start a feed: a supplier's export finishing, a deploy, a Google Apps Script when a sheet changes. On the feed's page, **Turn on the webhook**. You get a URL and a token, shown once (only a hash is stored).

```bash
curl -X POST https://example.com/!/ingest/feeds/supplier/run \
     -H "Authorization: Bearer ing_…"
```

| Response | |
|---|---|
| `202` | Queued. The body has the run's id |
| `401` | No token or the wrong one (also for a feed with no webhook, or no such feed) |
| `409` | Already running. The body has the running run's id |
| `423` | The feed is paused |
| `429` | More than 30 requests a minute |

The token can be sent as `X-Ingest-Token` instead. **Make a new token** replaces it; **Turn off** removes it. Duplicating a feed never copies its token.

## Chaining feeds

**Then run** starts other feeds after a successful run: categories before products, a catalogue before its stock.

```yaml
then_run:
  - stock_levels
```

## Queues and big feeds

Each run is a chain of queued jobs: fetch, a batch of items at a time (`chunk_size`, 100), then the finish. Batches run one after another, never side by side, so an author created by one book is there for the next. Before processing, the feed is read once into a working file, so later batches don't read the feed again, and XML and large JSON are streamed.

With `quiet_saves` (the default), items are saved without Statamic's events, and the finish brings search indexes and the static cache up to date for what changed, then makes **one Git commit** for the run (with Statamic's Git integration on), such as "Ingest: Supplier catalogue — 3 created, 41 updated, 0 unpublished, 0 deleted".
