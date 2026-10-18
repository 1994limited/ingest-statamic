# Field types

What each fieldtype accepts from a feed, and the settings its mapping can have.

## Text

**Text**, **Textarea**, **Link**, **Video**, **Color**: text. HTML is removed and entities decoded, because Antlers prints `{{ title }}` as it is and HTML from a feed mustn't reach the page. For a field shown with HTML on purpose, set **HTML in the feed** to **Keep safe tags** (`html: sanitize`), or **Keep everything** (`html: trusted`) for a feed you control.

**Slug**: slugified. **Code**: kept exactly as it came.

## Numbers

**Integer**, **Float**, **Range**: understands `1,234.50`, `1.234,50`, `12,99`, `£12.99` and `1 234`. Text that isn't a number is an error for that field.

## Yes or no

**Toggle**: `true`, `yes`, `y`, `1`, `on`, `live`, `published`, `active` mean yes; `false`, `no`, `n`, `0`, `off`, `draft`, `inactive` and empty mean no. Add a feed's own words:

```yaml
in_stock:
  path: availability
  true_values: ['in stock', 'available']
  false_values: ['sold out']
```

## Options

**Select**, **Radio**, **Button group**, **Checkboxes**: a value matches an option's key or label, ignoring case. For values that differ, add translations:

```yaml
format:
  path: binding
  values:
    - { from: 'BB', to: hardback }
    - { from: 'BC', to: paperback }
  unknown: skip     # skip (with a warning), raw (save as is), or error
```

Checkboxes and multiple selects take a list.

## Lists

**List**, **Taggable**: a list from the feed, or text split on `|` or `,` (or the mapping's `delimiter`). **Array**: a keyed object. **Table**: a list of rows. **Dictionary**: matched to the dictionary's keys, then labels.

## Dates and times

**Date**: ISO dates, RFC 2822 (`Tue, 16 Jul 2013 17:14:36 +0000`), Unix timestamps in seconds or milliseconds, and day-first dates like `03/04/2026` (3 April). Set `format` when a feed sends something else, and `timezone` when its times aren't in the site's timezone. Dates are stored in UTC, as Statamic does. A range field reads its end from `end_path`.

```yaml
event_date:
  path: start
  format: 'm/d/Y H:i'
  timezone: America/New_York
```

**Time**: `09:00`, `9am`, `21:30`.

## Rich text

**Bard**: HTML from the feed is sanitised (an allow-list of safe tags; no scripts, iframes, event handlers or `javascript:` links) and converted to Bard's structure. Plain text becomes paragraphs. Set `markdown: true` for a feed that sends Markdown. Bard fields that save HTML get the sanitised HTML. Ingest never changes the field's settings.

**Markdown**: HTML is converted to Markdown. Markdown with HTML inside it is passed through HTML and back, so the HTML is sanitised too.

## Relationships

**Entries**, **Terms**: each value is matched to existing content by `match`: `title` (the default), `slug`, `id`, or any field's handle (`isbn`). With `create: true`, anything that doesn't exist yet is created in the field's first collection or taxonomy. A dry run says what it would create without creating it.

```yaml
authors:
  path: contributors.*.name
  match: title
  create: true
book:
  path: ISBN
  match: isbn     # an entries field matched on the books' isbn field
```

**Users**: matched by `email` (the default), `id` or `name`. A feed never creates users through a relationship.

## Images and files

**Assets**: each URL is downloaded into the field's container.

```yaml
cover_image:
  path: images.0.url
  folder: 'books/{isbn}'
  alt: title
  refresh: false
```

- A URL is downloaded once. Later runs reuse the asset (set `refresh: true` to check for a new version each run).
- The same file at two URLs becomes one asset.
- Only files that really are images or PDFs (checked from the file, not the server's word) and under 20 MB are kept; see `ingest.assets` in the [configuration](configuration.md). SVG is refused unless you allow it, because SVG can carry scripts.
- File names are made safe (no spaces or accents) and never overwrite an existing file.
- Relative URLs are resolved against the feed's URL; use the `absolute_url` transform to give another base.
- One image that fails is a warning: the item and its other images are still saved.

For files already in the container, set `source: existing` and map their paths.

## Grids and Replicators

Map the field to a list in the item (`path: formats`); each element becomes a row. Then map the row's fields with paths inside the element; start a path with `^.` to read from the main item.

```yaml
formats:
  path: formats
  mode: merge
  merge_key: sku
  fields:
    sku: { path: sku }
    binding: { path: binding }
    price: { path: price }
```

| `mode` | |
|---|---|
| `replace` | The feed's rows replace the field's rows (the default) |
| `append` | The feed's rows are added after the existing ones |
| `merge` | Rows are matched on `merge_key`: matched rows are updated (keeping fields the feed doesn't map), new ones added, others kept |

Replicators also need the set: a fixed `set: quote`, or `set_path: type` (with `set_map` when the feed's names differ). Each set's fields can be mapped separately under `sets.{set}.fields`.

Rows get ids that stay the same from run to run, so an unchanged feed doesn't look changed.

## Groups

Map a group's fields one by one (paths are in the item), or point it at an object with `path` and map inside that.

## Cargo

**Money** and **Product variants**: see [Cargo](cargo.md).

## Anything else

Fieldtypes Ingest doesn't know are given the value as it is (text has its HTML removed). To handle one properly, [register a field mapper](php-api.md#your-own-field-mapper).
