# RSS and Atom aggregation

Bring posts from publishers, partners or your own other sites into a news section. RSS and Atom read into the same `_feed` fields, so one mapping fits both.

```yaml
title: 'Publisher news'
source:
  type: url
  url: 'https://publisher.example/feed/'
destination: { type: entries, collection: news, blueprint: post }
key: [_feed.id]
mapping:
  title: { path: _feed.title }
  date: { path: _feed.date }
  summary: { path: _feed.summary, transforms: [text, { name: truncate, args: [200] }] }
  body: { path: _feed.content }
  source_link: { path: _feed.link }
  by: { path: _feed.author }
  categories: { path: _feed.categories }
  image: { path: _feed.image, folder: news }
schedule: { frequency: hourly }
```

- Missing posts are left alone (the default): an RSS feed only holds its latest items, and older posts falling off it isn't a reason to remove them.
- Several feeds into one collection: make one feed per source, each with the same mapping. A `source` field with a fixed `value` says which is which.
- Only want some posts? Filter on `_feed.categories` with `in`.
- Podcasts: `_feed.enclosure.url` is the audio file; `itunes:duration` is at `itunes:duration`.

Content from RSS is sanitised like any other HTML; images are downloaded and stored on your site, so check you're allowed to republish them.
