# Transforms

Transforms change a feed's value before it's saved. They run in order:

```yaml
price:
  path: rrp
  transforms:
    - trim
    - { name: replace, args: [' GBP', ''] }
    - { name: math, args: ['* 1.2'] }
    - { name: round, args: [2] }
```

On the form, open a field and click **Transform the value**. Text transforms apply to each value of a list.

## Text

| Transform | Arguments | |
|---|---|---|
| `trim` | characters (optional) | |
| `lower`, `upper`, `title`, `ucfirst` | | |
| `slugify` | | |
| `strip_tags` | | |
| `decode_entities` | | `&amp;` → `&` |
| `text` | | HTML to plain text, keeping word breaks |
| `sanitize_html` | | Keep safe HTML only |
| `html_to_markdown`, `markdown_to_html` | | |
| `replace` | find, replace with | |
| `regex_replace` | pattern, replace with | `/\s+\(PB\)$/` |
| `prepend`, `append` | text | Not added to empty values |
| `truncate` | length, ending | |
| `absolute_url` | base URL (optional) | Relative to the feed's URL by default |

## Lists

| Transform | Arguments | |
|---|---|---|
| `split` | separator | Text to a list |
| `join` | separator | A list to text |
| `first`, `last`, `nth` | position (`nth`) | |
| `pluck` | path | `[{name: …}]` → names |
| `unique`, `filter_empty`, `sort`, `reverse`, `count` | | |

## Values

| Transform | Arguments | |
|---|---|---|
| `default` | value | When empty |
| `map` | a table, a fallback | `{ name: map, args: [{ Y: yes, N: no }, '*'] }`. A fallback of `*` keeps unmatched values |
| `number` | | `"1.234,50"` → `1234.5` |
| `math` | operator, operand | `['*', 1.2]` or `['* 1.2']` |
| `round` | decimal places | |
| `money` | decimal places (2) | `"12.99"` → `1299` |
| `boolean` | true words, false words | |
| `date` | format in, timezone, format out | Out defaults to ISO 8601 |
| `json_decode` | | A JSON string inside a feed |

## Statamic's modifiers

`{ name: modifier, args: [name, …arguments] }` runs one of Statamic's own modifiers: `ascii`, `camelize`, `dashify`, `deslugify`, `ensure_left`, `ensure_right`, `entities`, `excerpt`, `kebab`, `lcfirst`, `ltrim`, `rtrim`, `safe_truncate`, `sentence_list`, `singular`, `plural`, `slugify`, `snake`, `squish`, `studly`, `swap_case`, `title`, `trim`, `ucfirst`, `upper`, `lower`, `widont`, `word_count`, `wrap`, `nl2br`, `smartypants`, `strip_tags`, `sanitize`, `decode`, `urlencode`, `urldecode`, `rawurlencode`, `mod`, `ceil`, `floor`, `format_number`, `replace`, `regex_replace`, `remove_left`, `remove_right`, `truncate`, `limit`, `offset`, `flatten`. Modifiers that render templates or read files aren't available to feeds.

## Your own

Register a transform in a service provider and it appears on the form:

```php
use NineteenNinetyFour\Ingest\Facades\Ingest;

Ingest::transform('add_vat', fn ($value, array $args, $context) => round($value * 1.2, 2));
```

See [the PHP API](php-api.md#transforms).
