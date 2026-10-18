# Google Sheets as a CMS

Some content lives best in a spreadsheet that editors already share: staff picks, a stockist list, a price list, opening hours. Ingest turns the sheet into entries or globals.

## Staff picks into entries

A sheet with columns **ISBN**, **Staff member**, **Why we love it**, **Featured?** and **Order**:

```yaml
title: 'Staff picks'
source:
  type: google_sheets
  url: 'https://docs.google.com/spreadsheets/d/…/edit#gid=0'
destination: { type: entries, collection: staff_picks, blueprint: pick }
key: [ISBN]
mapping:
  title: { template: '{Staff member} picks {ISBN}' }
  book: { path: ISBN, match: isbn }
  reason: { path: 'Why we love it' }
  staff: { path: 'Staff member' }
  featured: { path: 'Featured?' }
  order: { path: Order }
sync:
  missing: { action: delete }
schedule: { frequency: minutes, every: 10 }
```

`book` links each pick to the product with that ISBN. Deleting a row deletes the pick.

## Opening hours into globals

A sheet (or a JSON file) with the shop's hours fills a global set: map a **Grid** of days with `path` pointing at the rows. See [Globals](../destinations.md#globals).

## Updating straight away

Instead of a schedule, an Apps Script can call the [webhook](../running.md#the-webhook) when the sheet is edited:

```js
function onEdit() {
  UrlFetchApp.fetch('https://example.com/!/ingest/feeds/staff_picks/run', {
    method: 'post',
    headers: { Authorization: 'Bearer ' + PropertiesService.getScriptProperties().getProperty('INGEST_TOKEN') },
  });
}
```

Keep the token in the script's properties, not in the code.
