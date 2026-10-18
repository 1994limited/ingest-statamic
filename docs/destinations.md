# Destinations

Where items go.

| Destination | Settings |
|---|---|
| Entries | `collection`, `blueprint`, `site` |
| Taxonomy terms | `taxonomy`, `blueprint`, `site` |
| Globals | `set`, `site` |
| Users | `send_activation` |
| Cargo products | `collection`, `blueprint`, `site`, `tax_class`. See [Cargo](cargo.md) |
| Cargo stock and prices | `collection`, `sku_field`. See [Cargo](cargo.md#stock-and-price-feeds) |

## The key

The key is a value in each item that identifies it and never changes: an ID, a SKU, an ISBN, a property reference. Ingest remembers which piece of content each key became, and that's how it finds the same item next time, even if its title or slug changes. Two values can make a key together (`branch_id` and `property_ref`).

Items without a key value fail with a clear message. If two items in one feed have the same key, the first is used and the second is skipped with a warning.

Every feed owns only what it created (or [adopted](#adopting-existing-content)). It never updates, unpublishes or deletes anything else, even in the same collection.

## Entries

Besides the blueprint's fields, a mapping can set:

| Field | |
|---|---|
| `slug` | Otherwise made from the title, unique in the collection |
| `date` | For dated collections; otherwise the time the entry was created |
| `published` | Otherwise new entries are published (or drafts, with **Publish new items** off) |
| `parent` | For structured collections: the parent's key in the same feed, or an entry ID |

## Taxonomy terms

Terms are created with a slug from the `slug` mapping or the title; the slug doesn't change after that. Values are saved in the feed's site.

## Globals

The feed's single item fills a global set: opening hours from a sheet, exchange rates from an API, a notice from a CMS export. No key is needed, and a globals feed never deletes anything.

## Users

Ingest can keep users in step with a membership system or CRM, with guardrails:

- A feed can never make anyone a super admin or set a password. Mapping `super` or `password` is refused.
- A feed never changes an administrator: super admins, and anyone with a role or group outside the allow-lists, are left alone, and an item that matches one fails.
- Roles and groups are only given if they're listed in `ingest.users.roles` and `ingest.users.groups`. Anything else is left out with a warning.
- New users can't sign in until they set a password. Turn on **Email new users a link to set their password** to send Statamic's activation email.
- Users missing from the feed are **blocked**, not deleted, when the feed is set to unpublish: their roles and groups are removed and `ingest_blocked` is set. Undoing the run gives them back. Deleting needs **Delete them**, and never deletes or blocks an administrator.
- Run history for users feeds is marked as personal data and its detail is cleared after `ingest.history.keep_personal_days` (7 days).

Users are matched by email when adopting existing accounts.

## Adopting existing content

If the collection already has the items (made by hand, or imported some other way), tell Ingest how to recognise them, or the first run creates them all again:

```yaml
sync:
  adopt:
    field: isbn     # the field on the site
    path: isbn      # the value in the feed
```

On the first run, an item with no content yet looks for an entry whose `isbn` equals the feed's `isbn`, and updates it rather than creating another. If more than one entry matches, or the match belongs to another feed, the item fails and says why; Ingest doesn't guess.

## Multisite

Each feed writes to one site (`destination.site`, the default site otherwise).

For a second language, make a second feed for the other site and set **Localize another feed's entries** to the first feed. Items with the same key become localizations of the first feed's entries, linked to them as their origin, rather than separate entries. A localized item that arrives before its origin is skipped and picked up on a later run.
