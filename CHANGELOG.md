# Changelog

## 1.0.0 (2026-10-18)

The first release.

### Added

- **Sources.** URLs and APIs with headers, query parameters, bearer tokens, API keys, basic auth and OAuth 2 client credentials; secrets as `env:` references only. Pagination by next link, Link header, page number, offset or cursor. Uploaded files, files on the server, and Google Sheets (public, or private with a service account). Unchanged feeds are skipped (ETag, Last-Modified, file hashes).
- **Formats.** JSON (streamed when large), JSON Lines, XML (streamed and hardened), RSS and Atom with normalised `_feed` fields, CSV/TSV with detected separators and encodings, and Rightmove BLM. Item paths, filters, and a structure picker in the Control Panel.
- **Destinations.** Entries (with slug, date, published and parent), taxonomy terms, globals, users (with guardrails: no super admins, no passwords, roles from an allow-list), Cargo products with variants, and Cargo stock-and-price feeds matched by SKU.
- **Mapping.** Paths, fixed values and templates; every Statamic fieldtype including Bard, Markdown, Replicator, Grid, Group, relationships that find or create, and assets downloaded once and shared; transforms and Statamic's own modifiers; empty-value rules; set-once fields.
- **Sync.** Keys and an ownership map; only mapped fields touched; unchanged items not saved; items missing from the feed left, unpublished or deleted behind a safety check; local edits kept or overwritten; adopting existing content; localizations for multisite.
- **Safety.** Dry runs with field-by-field changes that can be applied as previewed; undo for every run; queued, chunked runs with progress and cancel; run history with retention; email and Slack notifications once per problem; stale-feed alerts.
- **Running.** Schedules, the `ingest:*` commands, a token webhook, chained feeds, and a dashboard widget.
- **Storage.** Files (feeds as YAML that deploy with the site) or the database, with `ingest:transfer` between them.
- **Developers.** The `Ingest` facade, per-feed PHP hooks, events, and your own sources, formats, destinations, transforms and field mappers. The `{{ ingest:feed }}` and `{{ ingest:source }}` tags.
- **Security.** SSRF protection with pinned addresses and allowed hosts, size and time limits, XXE-safe XML, HTML sanitising, file type checks on downloads.
