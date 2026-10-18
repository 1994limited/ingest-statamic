# Ingest documentation

Ingest keeps Statamic in step with external feeds. A feed says where to read from (a URL, an API, a file or a Google Sheet), which items to read, where they go (entries, terms, globals, users, Cargo products) and how each field is filled. It runs on a schedule, on demand, from the command line or from a webhook. Every run can be previewed first and undone afterwards. Every template example has an Antlers and a Blade version.

## Setting up

1. [Installation](installation.md): requirements, installing, the scheduler and the queue, licensing.
2. [Quick start](quick-start.md): your first feed, from a URL to entries, in ten minutes.
3. [Configuration](configuration.md): the Control Panel settings, every option in `config/ingest.php`, storage drivers.

## Setting up feeds

4. [Sources](sources.md): URLs and APIs, authentication, secrets, pagination, uploads, server files, Google Sheets.
5. [Formats and items](formats.md): JSON, XML, RSS, Atom, CSV, BLM; item paths; filters.
6. [Destinations](destinations.md): entries, terms, globals, users; keys; adopting existing content; multisite.
7. [Mapping](mapping.md): paths, fixed values and templates; empty values; set-once fields.
8. [Field types](field-types.md): what each fieldtype accepts, from text to Bard, Replicator, relationships and assets.
9. [Transforms](transforms.md): changing values on the way in.
10. [Sync rules](sync-rules.md): creating, updating, missing items, the safety check, local edits.

## Running feeds

11. [Running and scheduling](running.md): the Control Panel, commands, schedules, the webhook, queues, chaining feeds.
12. [Dry runs, history and undo](dry-runs-and-undo.md): previewing, applying, reading a run, undoing, cancelling.
13. [Notifications](notifications.md): email and Slack when a feed fails, stops or goes quiet.

## Integrations

14. [Cargo](cargo.md): products with variants, prices and stock; stock-and-price feeds by SKU.
15. [Google Sheets](google-sheets.md): public sheets as a CMS, and private sheets with a service account.

## Front end and developers

16. [Templates](templates.md): showing where content came from and when it last synced.
17. [PHP API, hooks and events](php-api.md): the `Ingest` facade, per-feed hooks, transforms, events, and your own sources, formats, destinations and field mappers.
18. [Security](security.md): what Ingest protects against, and what to keep in mind.

## Recipes

19. [A supplier catalogue into Cargo](recipes/supplier-catalogue.md)
20. [Estate-agent listings (BLM and XML)](recipes/estate-agents.md)
21. [A job board](recipes/jobs.md)
22. [Events from a ticketing API](recipes/events.md)
23. [Google Sheets as a CMS](recipes/sheets-cms.md)
24. [RSS and Atom aggregation](recipes/rss.md)

## More

25. [The demo shop](demo.md): Dog-ear Books, seven feeds and a feed lab to try every feature.
26. [Troubleshooting](troubleshooting.md): common problems and fixes.
