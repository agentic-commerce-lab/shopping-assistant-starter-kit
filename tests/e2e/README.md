# End-to-end checks

These run a real browser against a **running shop**. They are not part of `composer run test`, and
they are not part of the CI gate — they need a shop with a configured model, and a real turn costs a
real model call.

## Running them

```bash
npx playwright install chromium     # once
SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/widget.spec.js
SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/pricing.spec.js
```

`pricing.spec.js` is the cheaper of the two: no model call, seconds rather than minutes, safe to run on
every change. See its docblock for what it checks and why. `widget.spec.js` is the one that spends
real money.

The shop must have the plugin installed and activated, a model configured, and the theme compiled:

```bash
bin/console theme:compile
```

## Why this layer exists at all

Everything testable in PHP is tested in PHP: the Twig gate that decides whether the widget renders,
the transcript codec, the card-id parser, the history payload. **This layer catches what none of that
can** — that the orb ended up behind the cookie-consent bar, that the panel never opened, that a card
rendered with no price, that a cart write did not actually reach the cart.

There are deliberately **no unit tests for the rendering functions**. A test asserting that
`renderMessage` produces a `div` with class `swag-assistant-message` passes by restating the code it
tests, and would keep passing while the panel was invisible.

## Two things that will bite you

**`reducedMotion: 'reduce'` is required, not stylistic.** Playwright will not click the orb while its
`breathe` animation runs — the element is never "stable", and the click fails on a timeout with
`element is not stable`. The spec sets it globally. It works because the widget's
`prefers-reduced-motion` support is real, which is a happy accident worth knowing: the media query
that makes the widget usable for someone who asked for less motion is the same one that makes it
testable.

**A real turn takes 16–19 seconds** at the worst measured, and 7–13 s on
`google/gemini-3.7-flash`. Card assertions use a 90-second timeout — see the note on queueing below
for why 60 was not enough, and why the headroom is worth keeping even when a turn comes back in
eight seconds.

## Status of the last full run

**Green. 22 passed, 2 skipped, 0 failed, 1.4 minutes** — `SHOP_URL=http://127.0.0.1:8000`,
Shopware 6.7.13.0, `google/gemini-3.7-flash`, 2026-09-04. This replaces a status section that had
read *"6 of 8 passed … the fixes have not been re-verified by a full run"* since the suite was eight
tests long; both of those failures are now asserted green, and the suite has since grown to 24.

Both skips are the designed outcome rather than a gap:

| Skipped | Why |
|---|---|
| a reply taller than the panel opens at its first line | `test.fixme`. A real defect, diagnosed — the log's first child carries `margin-top: auto`, so free space decides the transcript's position while free space is still changing. Fixing it changes how a one-message conversation looks, which is a design decision and not taken in a test |
| a configured primary colour is the one the entry point paints with | data-dependent: the test reads `--swag-assistant-primary` off the page and skips when the shop has no primary configured, rather than asserting a colour written into the test |

The two failures this section used to record, kept because the second one is still the thing to
understand before widening the suite:

| Was failing | Cause | Fix |
|---|---|---|
| over-long message not refused | **a real bug.** The composer carried `maxlength="2000"`, so the browser truncated the input silently and the over-limit state was unreachable dead code | `maxlength` removed; the composer now refuses visibly |
| card assertion timed out | too-tight timeout, not a widget fault — it passed in isolation in 27.5 s | `TURN_TIMEOUT` raised 60 s → 90 s |

**The server finishes a turn even after the client disconnects** — measured: a request aborted at 3 s
still landed both turns at +15 s. So a test that navigates away leaves work in flight and the next
live turn queues behind it. Adding more real-turn tests makes that worse, not linearly.

The suite fires **four live model calls** — the four assertions that use `TURN_TIMEOUT` — so it costs
money and takes minutes. It is not in CI for exactly that reason.

## Shop data these checks assume

`pricing.spec.js` needs at least one product carrying advanced (tiered/rule-based) prices. The demo
shop has hundreds, and the SQL that finds them is in `pricing.spec.js`'s own docblock — see there
rather than here, so the query and the test it serves cannot drift apart.

Its second assertion — that a card states the quantity its price assumes — needs a product with a
minimum purchase above one. **The demo shop has none, so that test skips by default**, and skipping
is the designed outcome rather than a gap to paper over: the constraint lives in `product.min_purchase`,
which is shop data this repository does not own, and a test pinned to a number someone seeded once
goes red for reasons that say nothing about the code.

To make it assert, give any visible product a minimum:

```sql
UPDATE product SET min_purchase = 4, purchase_steps = 4
WHERE id = UNHEX('01a01b4f981c70eabd51f14e553875ec');   -- Aerodynamic Concrete PortGear
```

Revert with:

```sql
UPDATE product SET min_purchase = 1, purchase_steps = 1
WHERE id = UNHEX('01a01b4f981c70eabd51f14e553875ec');
```

Run `bin/console cache:clear` after either. Point the test at a different product with
`PRICING_MIN_PURCHASE_ID=<32-hex id>`; it reads the quantity from the shop and never hardcodes it.

## What is not covered here

- The correction notice for prose that contradicts the cards. It only appears when the model actually
  makes an unbacked availability claim, which is intermittent by nature, so asserting it in an e2e
  run would make the suite flaky. It was verified by replaying the exact payload
  `AssistantController` emits — see the commit that added it.
- A German storefront. The snippets exist and the locale reaches `Intl.NumberFormat`, but no sales
  channel in the test shop runs `de-DE`.
