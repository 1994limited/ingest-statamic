# Templates

Imported content is ordinary Statamic content: show it with the usual tags. Ingest adds one tag, for saying where content comes from and when it last synced.

## A feed's last sync

```antlers
{{ ingest:feed handle="stock_levels" }}
    {{ if last_synced }}<p>Stock checked {{ last_synced | relative }}.</p>{{ /if }}
{{ /ingest:feed }}
```

<details>
<summary>Blade</summary>

```blade
@php($feed = Statamic::tag('ingest:feed')->params(['handle' => 'stock_levels'])->fetch())
@if ($feed && $feed['last_synced'])
    <p>Stock checked {{ $feed['last_synced']->diffForHumans() }}.</p>
@endif
```

</details>

## Where this entry came from

On an entry's page:

```antlers
{{ ingest:source }}
    <p class="meta">From our supplier's catalogue, checked {{ last_synced | relative }}.</p>
{{ /ingest:source }}
```

<details>
<summary>Blade</summary>

```blade
@php($source = Statamic::tag('ingest:source')->params(['id' => $id])->fetch())
@if ($source)
    <p class="meta">From our supplier's catalogue, checked {{ $source['last_synced']?->diffForHumans() }}.</p>
@endif
```

</details>

Nothing is output for content no feed owns. Pass `id` to ask about another entry.

## Variables

| Variable | |
|---|---|
| `title` | The feed's name |
| `handle` | |
| `last_synced` | When its last successful run finished (a date), or nothing |
| `last_status` | `completed` or `completed_with_errors` |
| `key` | `ingest:source` only: the item's key in the feed |

## Static caching

`last_synced` changes with every run. If pages are statically cached, Ingest already clears the cache for content a run changes; wrap the tag in `{{ nocache }}` if the time itself must always be current.
