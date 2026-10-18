# Estate-agent listings (BLM and XML)

Agency software (Reapit, Alto, Jupix, Vebra and others) exports listings as Rightmove BLM files, XML, or JSON, often dropped onto the server by FTP. Ingest reads all three.

## A BLM file dropped by FTP

```yaml
title: 'Listings'
source:
  type: path
  path: '/home/forge/drops/listings.blm'
  archive: true
format: { type: blm }
destination: { type: entries, collection: properties, blueprint: property }
key: [AGENT_REF]
filters:
  - { path: STATUS_ID, op: in, value: '0,1,2,3' }   # available, SSTC, SSTCM, under offer
mapping:
  title: { template: '{ADDRESS_1}, {TOWN}' }
  postcode: { template: '{POSTCODE1} {POSTCODE2}' }
  price: { path: PRICE }
  bedrooms: { path: BEDROOMS }
  summary: { path: SUMMARY }
  description: { path: DESCRIPTION }
  features: { template: '{FEATURE1}|{FEATURE2}|{FEATURE3}|{FEATURE4}|{FEATURE5}' }   # a list field: split on |
  transaction:
    path: TRANS_TYPE_ID
    values: [{ from: '1', to: sale }, { from: '2', to: let }]
  status:
    path: STATUS_ID
    values: [{ from: '0', to: available }, { from: '1', to: sstc }, { from: '2', to: sstcm }, { from: '3', to: under_offer }]
  images:
    template: 'https://media.agency.example/{MEDIA_IMAGE_00}'
    folder: 'properties/{AGENT_REF}'
sync:
  missing: { action: unpublish }
schedule: { frequency: minutes, every: 30 }
```

Add the drops folder to `ingest.server_paths`. `archive: true` moves each file aside after a successful run, so the next drop is the only one read.

A property that's sold or withdrawn leaves the file and is unpublished; its page goes away and comes back if it's relisted. The safety check means a truncated upload can't take the whole site's listings down.

## Kyero and other XML

```yaml
format: { type: xml, item_path: root/property }
key: [ref]
mapping:
  title: { template: '{type} in {town}' }
  price: { path: price }
  bedrooms: { path: beds }
  description: { path: desc.en }
  images: { path: 'images.image.*.url', folder: 'properties/{ref}' }
```

Two branches in one feed? Use a combined key: `key: [branch_id, ref]`.
