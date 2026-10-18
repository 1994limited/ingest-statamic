# A supplier catalogue into Cargo

A book wholesaler has an API: a paginated JSON catalogue with formats, prices and stock, and a stock-and-price CSV that changes every few minutes. This keeps a Cargo shop in step with both. It's the [demo shop](../demo.md)'s setup.

## The products' blueprint

A "Book with editions" blueprint in the products collection: title, ISBN, byline, description (Bard), authors (entries: Authors), subjects (terms: Subjects), publisher, pages, published (date), cover image (assets), product variants with `sku` and `stock` option fields, and a tax class.

## The catalogue feed

```yaml
title: 'Supplier catalogue'
source:
  type: url
  url: 'https://supplier.example/api/books'
  auth: { type: bearer, token: 'env:SUPPLIER_TOKEN' }
  headers: { Accept: application/json }
  pagination: { type: page, param: page, last_page_path: meta.last_page }
format: { type: json, item_path: data }
destination:
  type: cargo_products
  collection: products
  blueprint: book_editions
  tax_class: general
key: [isbn]
filters:
  - { path: status, op: equals, value: live }
mapping:
  title: { path: title }
  isbn: { path: isbn }
  byline: { path: 'contributors.*.name', transforms: [first] }
  authors: { path: 'contributors.*.name', match: title, create: true }
  subjects: { path: subjects, match: title, create: true }
  publisher: { path: publisher }
  pages: { path: pages }
  published_on: { path: published }
  description: { path: description_html }
  cover_image: { path: cover, folder: 'covers/{isbn}', alt: title }
  product_variants:
    path: formats
    dimensions: [{ name: Edition, path: binding }]
    sku: sku
    price: { path: price, create_only: true }
    stock: { path: stock, create_only: true }
sync:
  missing: { action: unpublish }
  local_edits: keep
schedule: { frequency: hourly, minute: 5 }
notify: { on: [failed, aborted], stale_after_hours: 26 }
then_run: [stock_levels]
```

- Withdrawn books are filtered out, so they're unpublished, and published again if they return.
- Authors and subjects are created the first time a book mentions them.
- Covers are downloaded once into `assets/covers/{isbn}/`.
- `local_edits: keep`: if the shop rewrites a description, the supplier's next change doesn't overwrite it.
- Price and stock are set when an edition first appears; the stock feed keeps them current.

## The stock feed

```yaml
title: 'Stock and prices'
source:
  type: url
  url: 'https://supplier.example/stock.csv'
  auth: { type: bearer, token: 'env:SUPPLIER_TOKEN' }
destination: { type: cargo_stock, collection: products }
key: [sku]
mapping:
  price: { path: price }
  stock: { path: stock }
schedule: { frequency: minutes, every: 15 }
```

Every row is matched by SKU to an edition, and only its price and stock change. Rows that haven't changed aren't written.

## Before going live

Run the catalogue with **Dry run**, check a few books field by field, then **Apply these changes**. Turn on the schedule once a real run looks right.
