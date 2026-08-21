# Demo catalogue export

Snapshot of the catalogue seeded into the demo shop
(`https://shoppingassistan-rschulte.eu-core-1.shopdev.de`), exported 2026-08-20.

| File | What it is | Rows |
| --- | --- | --- |
| `catalog.json` | Complete catalogue, parents with their variants nested. Properties and variant options are real objects. | 28 parents / 19 variants |
| `catalog.csv` | Same data, flat — one row per sellable item (parent *and* variant), `;`-delimited. | 47 |
| `shopware-default-product-export.csv` | The shop's own export via the `default_product` profile. Re-importable through Administration → Content → Import/Export. | 28 |

## Why three files

The Shopware `default_product` profile exports **parents only** — its criteria excludes
variants — so `shopware-default-product-export.csv` is missing all 19 variant rows and
their variant-specific prices (e.g. `fx-026-black-m` at 54.90 vs. the 49.90 parent).
It is included anyway because it is the only one of the three that imports back into
Shopware unchanged. Use `catalog.json` / `catalog.csv` when you need the full picture.

## Notes

- Prices are EUR, `priceGross` is what the storefront shows, 19 % standard rate.
- `available: false` means stock 0 with `isCloseout` on — the storefront reads
  "No longer available". Four items are deliberately in that state: `fx-011`,
  `fx-026-blue-m`, `fx-030-tan-700`, `sk-110`.
- `imageUrl` points at the live media; the images are not duplicated into this folder.
- The `fx-*` entries mirror `tests/Fixtures/catalog.json` verbatim, including the
  prompt-injection description on `fx-017` and the deliberate mis-categorisation of
  `fx-021` into "Merch". The `sk-*` entries are additional breadth.
