# Sidepath — brand assets

Logo for the demo shop. True vector, no embedded fonts: the wordmark is outlined
Barlow Condensed Bold (SIL OFL), so it renders identically everywhere.

| File | Use |
| --- | --- |
| `logo.svg` | Header lockup on light grounds. Live as the theme logo (desktop/tablet/mobile). |
| `logo-inverse.svg` | Same lockup on ink grounds — hero, footer, social card. |
| `mark.svg` | Mark alone, square. Live as the favicon (rasterised to 256 px PNG). |

## Palette

| Token | Hex | Where |
| --- | --- | --- |
| Paper | `#f2f0ed` | Page background — **identical to the product-photo background**, so product shots have no visible cut-out edge. |
| Ink | `#17191b` | Text, buy buttons, editorial bands |
| Teal | `#0e8c88` | Links, accents, the mark's side path |
| Rust | `#c4491f` | Error / sold-out signal |
| Hairline | `#d5d0c8` | Rules, card borders |

## Type

- Display: **Barlow Condensed** 700/800, uppercase, tight tracking
- Meta: **IBM Plex Mono** 400/500, uppercase, wide tracking
- Body: Inter (the only face the default theme bundles)

Barlow Condensed and IBM Plex Mono are loaded by the landing page's CMS block, so
they apply on the home page only. A shop-wide swap needs a child theme.
