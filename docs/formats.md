# Formats and items

## Formats

Ingest works out the format from the file itself (leave **Format** on **Detect**), or you can choose it.

| Format | Notes |
|---|---|
| JSON | Files over 20 MB are streamed: one item in memory at a time |
| JSON Lines (NDJSON) | One object per line |
| XML | Streamed with XMLReader. Hardened: no network access, no external entities |
| RSS | XML with `rss/channel/item` as the items, plus [normalised fields](#rss-and-atom) |
| Atom | XML with `feed/entry` as the items, plus the same normalised fields |
| CSV / TSV | The separator (comma, semicolon, tab, pipe) and encoding (UTF-8, Windows-1252) are detected; a byte-order mark is handled. Options: `header` (the first row names the columns; without it columns are A, B, C…), `skip_rows`, `delimiter`, `encoding` |
| Rightmove BLM | Items keyed by the field names in `#DEFINITION#` |

If the address returns a web page instead of a feed (a sign-in or error page), the run fails with that explanation rather than importing nothing.

## The item path

The item path says where the repeating items are. The **Items** tab shows the feed's structure, with how many items each part holds. Click one to use it.

| Feed | Item path |
|---|---|
| `{"data": [ … ]}` | `data` |
| `{"results": {"books": [ … ]}}` | `results.books` |
| `<catalogue><book>…</book><book>…</book></catalogue>` | `catalogue/book` |
| RSS / Atom | leave empty (`rss/channel/item` / `feed/entry`) |
| A CSV | leave empty: each row is an item |
| A single object (a "show" endpoint, a settings file) | leave empty: the object is one item |
| Lists inside items: `{"data": [{"subjects": ["Crime", "Poetry"]}]}` | `data.*.subjects.*`: each subject is an item, with its value at `value` |

## Paths

The same syntax finds values inside an item, for keys, mapping and filters:

| Path | Finds |
|---|---|
| `title` | A key |
| `author.name` | A key inside a key |
| `images.0.url` | The first image's URL |
| `formats.*.sku` | Every format's SKU (a list) |
| `prices.~` | Every value of a keyed object |
| `@id` | An XML attribute |
| `contributors/contributor` | `/` works as well as `.` |

XML gives one child as a single value and several as a list. `*` treats a single value as a list of one, so `formats.*.sku` works whether a book has one format or three. An XML element with attributes and text gives its text where a value is expected.

## RSS and Atom

Besides their own elements, RSS and Atom items get a `_feed` set that reads the same for both, so one mapping works for a mix of feeds:

| Path | |
|---|---|
| `_feed.id` | The guid or id (a good key) |
| `_feed.title`, `_feed.link`, `_feed.author` | |
| `_feed.date` | Published or updated date |
| `_feed.summary`, `_feed.content` | `description` and `content:encoded` (RSS), `summary` and `content` (Atom) |
| `_feed.image` | From `media:content`, `media:thumbnail` or an image enclosure |
| `_feed.categories` | A list |
| `_feed.enclosure.url`, `.type`, `.length` | Podcasts |

## Filters

Only items that pass every filter are imported:

```yaml
filters:
  - { path: status, op: equals, value: live }
  - { path: price, op: gt, value: 0 }
  - { path: subjects, op: in, value: 'Crime, History' }
```

Operators: `equals`, `not_equals`, `contains`, `not_contains`, `starts_with`, `in`, `not_in`, `empty`, `not_empty`, `gt`, `gte`, `lt`, `lte`, `matches` (a regular expression). Comparisons ignore case. On a list, a positive rule passes if any value matches; a negative one if none does.

Items a filter leaves out count as missing from the feed, so a feed set to unpublish missing items unpublishes, for example, books whose status becomes `withdrawn`.
