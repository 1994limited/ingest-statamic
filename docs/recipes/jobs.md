# A job board

An ATS or job board publishes an XML or JSON feed of open roles. Jobs appear on the site as they're posted and come down as they close.

```yaml
title: 'Jobs'
source:
  type: url
  url: 'https://jobs.example/feeds/acme.xml'
format: { type: xml, item_path: source/job }
destination: { type: entries, collection: jobs, blueprint: job }
key: [referencenumber]
mapping:
  title: { path: title }
  location: { path: city }
  job_type:
    path: jobtype
    values: [{ from: 'Full time', to: full_time }, { from: 'Part time', to: part_time }]
  salary: { path: salary }
  closes: { path: expirationdate }
  description: { path: description }
  apply_url: { path: url }
sync:
  missing: { action: unpublish }
  safety: { allow_empty: true, max_removal_percent: 100 }
schedule: { frequency: hourly }
notify: { on: [failed] }
```

- Closed roles leave the feed and are unpublished.
- A small company may really have no open roles: `allow_empty` and a 100% limit let an empty feed unpublish everything. Leave them off for feeds where empty would mean broken.
- Descriptions arrive as HTML and are sanitised into Bard.
- Listen for `ItemSaved` with `action: created` to post new roles elsewhere ([events](../php-api.md#events)).
