# Events from a ticketing API

A ticketing platform's API lists your events: paginated JSON behind a token, with dates in a timezone and ticket availability.

```yaml
title: 'Events'
source:
  type: url
  url: 'https://api.tickets.example/v1/organisers/123/events'
  auth: { type: bearer, token: 'env:TICKETS_TOKEN' }
  query: { status: live }
  pagination: { type: cursor, path: pagination.next_cursor, param: cursor }
format: { type: json, item_path: events }
destination: { type: entries, collection: events, blueprint: event }
key: [id]
filters:
  - { path: status, op: not_equals, value: cancelled }
mapping:
  title: { path: name }
  date: { path: start.local, timezone: Europe/London }
  ends_at: { path: end.local, timezone: Europe/London }
  venue: { path: venue.name, create_only: true }
  summary: { path: summary }
  ticket_price: { path: ticket_classes.0.cost.major_value }
  tickets_left: { path: capacity_remaining }
  image: { path: logo.original.url, folder: events }
sync:
  missing: { action: unpublish }
schedule: { frequency: minutes, every: 30 }
```

- `date` sets the entry's date in a dated collection. Make the collection's future entries public and past ones private, and events disappear by themselves after they happen.
- Cancelled events are filtered out, so they're unpublished.
- `create_only` on the venue lets editors tidy its name on the site.
- APIs with OAuth client credentials use `auth: { type: oauth2, token_url: …, client_id: …, client_secret: 'env:…' }`.
