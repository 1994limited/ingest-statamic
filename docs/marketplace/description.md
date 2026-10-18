**Keep Statamic in step with the feeds you don't control: supplier catalogues, stock files, property listings, job boards, ticketing APIs, spreadsheets and RSS. Ingest reads them on a schedule, changes only what changed, and lets you preview and undo every run.**

Moving a site from WordPress once? Statamic's official Importer does that well. Ingest is for the feeds that keep coming: the same feed, every hour, kept in sync.

## Any feed

JSON, JSON Lines, XML, RSS, Atom, CSV/TSV and Rightmove BLM, from a URL or API (bearer tokens, API keys, basic auth, OAuth 2), an uploaded file, a file on the server, or a Google Sheet. Five kinds of pagination. Big files are streamed, not loaded whole.

## Into anything

Entries, taxonomy terms, globals and users. With [Cargo](https://statamic.com/addons/duncanmcclean/cargo): products with variants, prices and stock, plus a fast stock-and-price feed matched by SKU.

## Real mapping

Every fieldtype, including Bard, Replicator, Grid and Group. Relationships that find or create entries and terms. Images downloaded once and shared. Dates, toggles and options that understand what feeds actually send. Transforms, templates, and Statamic's own modifiers.

## Sync, not just import

A key matches feed items to content. Only fields you map are touched, unchanged items aren't saved again, and items that leave the feed can be left, unpublished or deleted. Edits made on the site can be kept.

## Safe to leave running

Dry runs show every change field by field, and apply exactly what you previewed. Every run can be undone. A feed that comes back empty, broken or half-missing never wipes your content. Failures email you (and Slack) once, not every 15 minutes.

## In the Control Panel

Set feeds up with a live preview of the real feed beside the mapping, watch runs as they go, and see what each one did. A dashboard widget shows which feeds need attention.

## For developers

Feeds are YAML files that deploy with the site (or database rows), with commands, a webhook, events, per-feed PHP hooks, and your own sources, formats, destinations and transforms.

## Requirements

- Statamic 6.35 or later
- PHP 8.3 or later
- A cron entry for Laravel's scheduler, for scheduled feeds
- A queue worker is recommended for large feeds
- Optional: Cargo 1.15 or later, for products, variants and stock
