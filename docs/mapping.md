# Mapping

The **Mapping** tab lists every field in the destination's blueprint. Fields you don't map are never read, cleared or changed. Click a field to map it. The value it would get for the item in the preview is shown beside it, so you can step through items and see the result.

**Map by name** maps every field whose handle matches a value in the feed (`title` to `title`, `isbn` to `ISBN`). Check what it chose.

## Where a value comes from

| | YAML | |
|---|---|---|
| From the feed | `path: author.name` | A [path](formats.md#paths) in the item. While a path box is focused, clicking a value in the preview fills it in |
| A fixed value | `value: Paperback` | The same for every item |
| A template | `template: '{first_name} {last_name}'` | Paths in braces are replaced by the item's values. Values are inserted as text: nothing in a feed is ever run as Antlers or PHP |

Then, in order:

1. **[Transforms](transforms.md)** change the value: trim it, replace text, convert a price, parse a date.
2. If there's still no value, **when the feed has no value** decides: clear the field (the default), keep what's there, or use a default.
3. The value is turned into what the [field type](field-types.md) stores.

```yaml
mapping:
  title:
    path: title
    transforms: [trim]
  subtitle:
    path: subtitle
    empty: keep
  pages:
    path: extent
    empty: default
    default: 0
```

## Set once

**Only when the item is created** fills a field when the item is new and never touches it again. After that, the site (or another feed) looks after it.

```yaml
mapping:
  blurb:
    path: description
    create_only: true
```

Use it for a starting description your editors rewrite, or a price a [stock feed](cargo.md#stock-and-price-feeds) keeps up to date.

## Inside rows

Grids, Replicators and Cargo variants map their own fields. Their paths are inside each row: `sku`, not `formats.*.sku`. Start a path with `^.` to read from the main item instead: `^.publisher`. See [Field types](field-types.md#grids-and-replicators).

## What changes, and when

Ingest keeps a fingerprint of the values it last wrote for each item. If the feed's values are the same next time, the item isn't touched at all: no save, no revision, no events. If something changed, only the fields that changed are written, and the run lists them old → new.
