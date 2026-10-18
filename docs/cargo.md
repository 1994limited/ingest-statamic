# Cargo

With [Cargo](https://statamic.com/addons/duncanmcclean/cargo) 1.15 or later (before 2.0), Ingest keeps products, variants, prices and stock in step with a supplier's or ERP's feeds. Without Cargo, or with a version this release wasn't built for, the Cargo destinations are hidden and everything else works as normal.

## Products

Choose **Cargo products** as the destination: an entry in one of Cargo's product collections (`statamic.cargo.products.collections`).

- **Price** fields (Cargo's money fieldtype) take `"12.99"`, `"12,99"` or `12.99` and store whole pence: `1299`. If the feed already sends pence, set `minor_units: true`.
- **Stock** is an integer field like any other.
- **Tax class**: set one under the destination for new products that don't map one. Cargo can't check out a product without a tax class.

## Variants

Map the **product variants** field to the repeating part of the item, one element per option:

```json
{
  "isbn": "9791001000373",
  "title": "An Orchard for Pip",
  "formats": [
    { "sku": "9791001000373-HB", "binding": "Hardback", "price": "25.65", "stock": 11 },
    { "sku": "9791001000373-PB", "binding": "Paperback", "price": "17.65", "stock": 100 }
  ]
}
```

```yaml
product_variants:
  path: formats
  dimensions:
    - { name: Edition, path: binding }
  sku: sku
  price: { path: price }
  stock: { path: stock }
```

- **Dimensions** are the variant names (Edition; or Colour and Size). Each needs a path to its value in the element. Option keys follow Cargo's own rule: the values joined with `_` (`Red_Small`).
- **SKU** is stored on each option (add an `sku` option field to the blueprint) and is how options are matched from run to run. Fields on options that the feed doesn't map are kept.
- Other option fields can be mapped under `fields`.
- `mode: merge` keeps options the feed doesn't list; by default the feed's options replace them.
- `stock.mode: lower_only` only ever lowers stock: a sale between feed runs is never undone.
- `create_only: true` on `price` and `stock` sets them for new options only, for when a [stock feed](#stock-and-price-feeds) keeps them up to date.

## Stock and price feeds

Suppliers often send a small file of SKUs, prices and stock every few minutes, separate from the catalogue. Choose **Cargo stock and prices** as the destination:

```yaml
title: 'Stock and prices'
source:
  type: url
  url: 'https://supplier.example/stock.csv'
destination:
  type: cargo_stock
  collection: products
  sku_field: sku
key: [sku]
mapping:
  price: { path: price }
  stock: { path: stock }
schedule:
  frequency: minutes
  every: 15
```

Each row is matched by SKU to a simple product (its `sku` field) or a variant option (its `sku` option field), and only its price and stock change. Nothing is created or removed. Rows with an unknown SKU are skipped and listed. Unchanged rows aren't written, so a 15-minute feed with no news costs almost nothing.

## Stock and sales

Cargo lowers stock when an order is placed. A feed that sets stock afterwards replaces that number with the supplier's. That's usually right (the supplier's count is the truth), but if orders happen between supplier updates, use `stock.mode: lower_only` on variants, or set stock only on creation and let Cargo look after it.

## Recipe

[A supplier catalogue into Cargo](recipes/supplier-catalogue.md) puts all of this together: a paginated catalogue API with covers, authors and subjects, and a stock feed beside it.
