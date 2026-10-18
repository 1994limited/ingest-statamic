# Dry runs, history and undo

## Dry runs

A dry run reads the feed and works out everything a run would do, without saving anything: no content, no relationships, no downloads, no notifications. Its page lists each item it would create, update, unpublish or delete, with every field's old and new value. Structured fields show only what moved: `product_variants › options.Paperback.price  1299 → 1449`.

![A dry run of the stock feed](images/cp-dry-run.png)

**Apply these changes** runs the feed with **exactly the items the dry run read**, not a fresh copy of the feed. What you previewed is what you get, even if the feed has changed since. If the feed's mapping, destination, key, filters or sync rules have been edited since the dry run, applying it is refused: run a new dry run to see what the new settings would do. A dry run's items are kept for a week.

## Reading a run

A run's page has:

- its status, and why it stopped if it didn't finish (the feed's error, or the safety check's reason);
- counts of what was created, updated, unchanged, unpublished or deleted, failed and skipped;
- every item that changed or had a problem, filterable and searchable, each with its changes field by field, its errors and warnings, and a link to edit it. Unchanged items are counted but not listed.

While a run is going, its page shows progress and has **Cancel**. Cancelling stops after the current batch; what was done before stays, and the run can be undone.

| Status | |
|---|---|
| Completed | |
| Completed with errors | Some items failed; the rest were saved |
| Stopped by safety check | Items were updated, but nothing was removed: see [the safety check](sync-rules.md#the-safety-check) |
| Failed | The feed couldn't be read or set up: nothing changed |
| Cancelled, Undone | |

## Undo

**Undo this run** on a run's page (or `php please ingest:undo {run}`):

- removes what the run created,
- puts back the values it changed (only the fields it changed),
- republishes what it unpublished,
- recreates what it deleted, from its snapshot, with the same id.

Anything edited on the site since the run is left as it is, field by field, and listed. Runs are undone newest first: undo a later run before an earlier one. Entries, terms and images a run created for relationship and asset fields are kept, since other content may use them by now.

The next run compares the feed afresh, so undoing a run and running the feed again brings its changes back.

Undo needs the run's detail. Runs older than the last 20 for a feed (`keep_runs`) keep only their summary, so plan to undo soon after a run that went wrong.

## History

Ingest keeps:

- every run's summary for a year,
- item-level detail for the last 20 runs per feed, and failed runs for 30 days,
- detail of [users](destinations.md#users) feeds for 7 days.

`ingest:prune` clears the rest daily. All three periods are in the [settings](configuration.md).
