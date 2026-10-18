# Troubleshooting

## Scheduled feeds don't run

The feeds page says "Scheduled feeds aren't running" when Laravel's scheduler hasn't run for five minutes. Add the [cron entry](installation.md#the-scheduler), and check `php artisan schedule:list` shows `ingest:schedule`.

## A run stays "Queued"

No queue worker is running. Start one (`php artisan queue:work`), or use the `sync` queue driver. A run with no progress for 30 minutes is marked failed so the feed can run again.

## "points to a private or internal address"

The feed's host resolves to a private network address, which Ingest refuses to protect the server. If that's intended (an intranet ERP, a local development site), add the host to `INGEST_ALLOWED_HOSTS`.

## "SSL certificate problem" on a local site

Herd and Valet sign local sites with their own certificate authority. Set `INGEST_HTTP_VERIFY` to its path (Herd: `~/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem`). Never turn checking off on a live site.

## "The environment variable … isn't set"

The feed uses `env:NAME` and `.env` on this server doesn't have it. Add it. The feed's page lists every variable a feed needs and whether it's set.

## "The feed URL returned a web page, not a feed"

The address gave HTML: a sign-in page, a cookie wall, or an error page. Check the URL in a private browser window, and whether the feed needs authentication.

## Every item fails with "no value for the feed's key"

The key's path doesn't match the items. Check the item path first (the preview shows one item at a time), then the key.

## Everything is created again

The items' key changed (the supplier switched IDs), or the content was made before Ingest. Set the key to a value that doesn't change, and use [adopting existing content](destinations.md#adopting-existing-content) on the first run.

## A run was "Stopped by safety check"

It would have unpublished or deleted more than the feed's limit (25% by default), or the feed came back empty. Look at the feed: if it's right, run it with **Ignore the safety limit**. See [the safety check](sync-rules.md#the-safety-check).

## Images aren't downloaded

The run's warnings say why for each one: not an allowed type (SVG, HTML instead of an image), too large, unreachable, or a relative URL with no base (use `absolute_url`).

## Changes made in the Control Panel are overwritten

That's **The feed wins**. Set **Fields edited on the site** to **Keep the site's edit**, or set a field [once](mapping.md#set-once).

## Search or the static cache are out of date

With `quiet_saves` on, Ingest updates them at the end of a run. A run that failed partway still updates what it changed; if you've turned `quiet_saves` off, Statamic's own events handle it.

## Big feeds are slow

Use the database driver for feeds of tens of thousands of items, run a queue worker, and give unchanged feeds nothing to do: a supplier that supports `ETag` or `Last-Modified` lets Ingest skip an unchanged feed in a second.
