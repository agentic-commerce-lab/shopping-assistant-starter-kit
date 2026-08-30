# Refuse a Family in the Cart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop `add_to_cart` accepting a product family, so a shopper cannot end up with a line nobody can say the colour of.

**Architecture:** One guard in `AddToCartTool`, placed with the other policy checks and reported through the same `PolicyDecision`/trace path they use. A card already knows whether its stock figure belongs to a variant, a family or a standalone product — `ProductCard::$stockSource` — so the guard is a single comparison against a fact the server already computed.

**Tech Stack:** PHP 8.2, Shopware 6.7, PHPUnit 11.

**Spec:** No design document. This closes a gap found while checking the shopper journey *"I want to select the correct variant … so that I do not add the wrong product to my cart"* against the code on 2026-08-30. The journey's selection half is built; this is the half that stops a wrong selection reaching the cart.

## The evidence this exists

Measured against the running demo shop on 2026-08-30, `GET /assistant/cards` for the family product `00235daf21160538fe65da8d8fa38876` ("Midi Bag 0539", five variants) returns:

```
stockSource=parent   stock=6   price=19.31
```

`AddToCartTool` would accept that id today. It looks the product up, finds a non-null card, passes it through the blocklist, and hands the family id to Shopware's cart. The shopper is then told *"Added 1 to the cart"* beside a card showing **19.31** — the family's cheapest entry point — and **6**, which is the sum across five variants. Which colour is in the cart is a question neither the shopper nor the shop can answer.

**Why no test caught it:** `FixtureIndex` never emits `StockSource::Parent` — a family parent is not a sellable unit there, so `FixtureCommerceGateway::product('fx-026')` returns null and `add_to_cart` already answers *"No such product in this shop."* Only the DAL gateway produces parent cards, because Shopware's search returns family parents alongside their children. The fixture cannot reproduce the case, which is exactly why the test that would have failed was never written.

**Why the UI check is not enough:** `card.js` already refuses to render an add button when `stockSource === 'parent'`. That is a courtesy in the browser. `AddToCartTool` is the write authority, and its own class docblock explains why it re-checks the blocklist rather than trusting the gateway to have remembered — *"this is the one tool with write authority and the only one whose mistake has legal consequences"*. The same argument covers variants; the line is simply missing.

## Global Constraints

- `php: ^8.2`; `shopware/core: ~6.7.0`. **No new Composer dependencies.**
- No Shopware type may appear in `AddToCartTool` — it sits above the gateway seam and may touch only the plugin's own DTOs.
- Tool result strings are English prose; the model localises them for the shopper. No snippets here.
- Files stay at or under **400 physical lines** (`composer quality:filesize`).
- `composer test` must pass with **no `.env` file present**; `composer quality` must pass end to end.
- **Never run `vendor/bin/mago analyze <path>`** — with a path argument it does not terminate. Use `composer typecheck`, `composer lint` or `composer quality`, in the foreground.
- mago's gates fail at error level via the pre-commit hook: 11 methods per class and a class-scoped cyclomatic-complexity budget of 10. The house resolution is a sibling file or class with a docblock explaining the split — `src/Core/Tool/CartCorrectionNote.php` and `tests/Core/Commerce/Dal/DalProductCardMapperAdvancedPriceTest.php` are the precedents. `AddToCartTool` is already near its budget; if the guard tips it, split rather than suppress and say so.
- House style: `declare(strict_types=1)`, docblocks that explain *why*.

---

### Task 1: `add_to_cart` refuses a family

**Files:**
- Modify: `src/Core/Tool/AddToCartTool.php` — one guard after the blocklist check, plus the class docblock
- Test: `tests/Core/Tool/AddToCartToolFamilyTest.php` (create)

**Interfaces:**
- Consumes: `ProductCard::$stockSource` (a `Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource`, cases `Variant`, `Parent`, `Product`); `PolicyDecision::block(string $reasonCode, string $message)`; the existing private `blocked(PolicyDecision): array` helper, which records the refusal to the trace under stage `AddToCartTool::TRACE_STAGE` with `policyVerdict: 'block'` and the decision's `policyReasonCode`.
- Produces: reason code `variant_required` on the `cart.add` trace stage. Nothing later depends on it.

**Placement, decided here so it is not re-litigated:** the guard goes **after** the blocklist check and **before** the cart-value check. The class docblock states that the blocklist runs immediately after the product loads *"because a blocked product must never be priced for the shopper, let alone added"* — that invariant stays first. The cart-value check is the first thing that multiplies a price, and pricing a family is exactly what must not happen, so the guard goes above it.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Tool/AddToCartToolFamilyTest.php`. The fixture gateway cannot produce a family card, so this needs a double that can — build it as an anonymous class implementing `CommerceGatewayInterface`, following the shape `tests/Core/Tool/AddToCartToolTest.php` already uses for its `scopeIgnoringGateway()` helper. Read that file first for the six methods the interface requires and for how it builds the tool.

```php
    /**
     * A gateway that returns a FAMILY card — a parent product whose stock figure is the sum across
     * its variants. `FixtureCommerceGateway` cannot do this: `FixtureIndex` never emits
     * `StockSource::Parent`, because a family parent is not a sellable unit there. Only the DAL
     * gateway produces these, which is why no fixture test could ever have caught the defect this
     * file exists for. Measured against the demo shop on 2026-08-30, a real one looks like
     * "Midi Bag 0539": five variants, stockSource=parent, stock 6, price 19.31.
     */
    private function familyGateway(): CommerceGatewayInterface
    {
        // ... anonymous class; product() returns the family card below, addToCart() records that it
        // was called so the test can prove it was not, cart() returns an empty CartSummary.
    }

    private function familyCard(): ProductCard
    {
        return new ProductCard(
            id: 'fx-026',
            parentId: null,
            name: 'Trail Jersey',
            description: 'A family, not a variant.',
            price: 19.31,
            currency: 'EUR',
            stock: 6,
            stockSource: StockSource::Parent,
            deliveryTime: null,
            url: '/detail/fx-026',
            imageUrl: null,
        );
    }

    public function testAFamilyIsRefusedRatherThanAdded(): void
    {
        // The defect: the widget refuses the button for a family, the tool did not. A shopper was
        // told "Added 1 to the cart" beside a price that is the family's cheapest entry point and a
        // stock figure summed across five variants — with no way for anyone to say which colour
        // was bought.
        $result = $this->toolWith($this->familyGateway())(variantId: 'fx-026', quantity: 1);

        self::assertArrayNotHasKey('cart', $result);
        self::assertStringContainsString('family', strtolower($result['note']));
    }

    public function testNothingReachesTheCartWhenAFamilyIsRefused(): void
    {
        // Asserting the note alone would pass against a tool that refuses politely and adds anyway.
        $gateway = $this->familyGateway();

        $this->toolWith($gateway)(variantId: 'fx-026', quantity: 1);

        self::assertSame(0, $gateway->addCalls);
    }

    public function testTheRefusalIsTraceableUnderItsOwnReasonCode(): void
    {
        // A merchant reading the trace must be able to tell this refusal from a blocklist hit or a
        // cart limit, all three of which reach the same stage.
        $tool = $this->toolWith($this->familyGateway());
        $tool(variantId: 'fx-026', quantity: 1);

        $codes = [];
        foreach ($this->trace->events() as $event) {
            if ($event->stage === AddToCartTool::TRACE_STAGE) {
                $codes[] = $event->payload['policyReasonCode'] ?? null;
            }
        }

        self::assertContains('variant_required', $codes);
    }

    public function testAnOrdinaryVariantIsStillAdded(): void
    {
        // The guard must not refuse what it was never meant to: the fixture's own sellable units
        // carry StockSource::Variant or StockSource::Product and go through unchanged.
        $result = $this->tool()(variantId: 'fx-026-blue-l', quantity: 2);

        self::assertArrayHasKey('cart', $result);
        self::assertSame('Added 2 to the cart.', $result['note']);
    }
```

`toolWith()`, `tool()` and `$this->trace` are helpers you write, mirroring `AddToCartToolTest`'s own construction of the subject. Do not import `AddToCartToolTest` or extend it — copy the small amount of setup, as the sibling test files in this repository already do.

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Tool/AddToCartToolFamilyTest.php
```

Expected: the three family cases FAIL — the tool adds the family and returns a `cart` key. `testAnOrdinaryVariantIsStillAdded` should PASS already; if it does not, your helpers are wrong, not the production code.

- [ ] **Step 3: Write the guard**

In `src/Core/Tool/AddToCartTool.php`, immediately after the blocklist check and before the cart-value check:

```php
        // A family is not a sellable unit. Shopware's search returns family parents alongside their
        // children, so `product()` answers for a parent id and the card that comes back carries the
        // family's aggregate stock and its cheapest entry price — 19.31 across five variants, in the
        // shop this was measured against. Adding that means a cart line whose colour nobody can name.
        //
        // `card.js` already refuses to render an add button for one. That is a courtesy in the
        // browser; this is the write authority, and the same reasoning that makes this class re-check
        // the blocklist rather than trust the gateway applies here. The reason code is its own, so a
        // merchant reading a trace can tell this apart from a blocklist hit.
        if ($filtered['cards'][0]->stockSource === StockSource::Parent) {
            return $this->blocked(PolicyDecision::block(
                'variant_required',
                'That is a product family rather than a single variant. Ask which options the shopper '
                . 'wants, resolve them with get_product, and add the variant it returns.',
            ));
        }
```

Read the surrounding lines before inserting: `$filtered['cards']` is the blocklist survivor, and the existing code already relies on it being non-empty at that point because the branch above returns when it is empty. Use whichever of `$card` or `$filtered['cards'][0]` the surrounding code treats as canonical — they are the same product, and consistency with the neighbours matters more than the choice.

Add `StockSource` to the file's imports.

Then extend the class docblock's list of what this tool refuses, in the voice of the existing text, so the next reader finds the family rule where the blocklist rule already is.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Core/Tool/ && composer test
```

Expected: PASS. Watch specifically that the existing `AddToCartToolTest` and `AddToCartToolStoredQuantityTest` still pass — their fixtures return `StockSource::Variant` or `Product` and must be unaffected.

- [ ] **Step 5: Run the size and quality gates**

```bash
composer quality:filesize && composer quality
```

Expected: PASS, all seven stages. `composer quality` aborts at its first failing stage, so confirm each one ran rather than assuming.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Tool/AddToCartTool.php tests/Core/Tool/AddToCartToolFamilyTest.php
git commit -m "fix(cart): refuse a product family instead of adding one"
```

---

### Task 2: Prove it against the running shop

The fixture cannot produce a family, so the deterministic test uses a double. This confirms the guard fires on the path that actually produces families.

**Files:**
- Modify: `docs/superpowers/plans/2026-08-30-refuse-a-family-in-the-cart.md` — record the observed result at the end of this task

**Interfaces:** consumes Task 1; produces nothing.

- [ ] **Step 1: Refresh the shop**

The demo shop mounts this repository, so PHP changes are live after a cache clear:

```bash
docker exec shopping-assistant-test-web-1 sh -lc 'php bin/console cache:clear'
```

- [ ] **Step 2: Confirm the shop still produces a family card**

```bash
curl -s "http://127.0.0.1:8000/assistant/cards?ids=00235daf21160538fe65da8d8fa38876"
```

Expected: one card with `"stockSource":"parent"`. If that product has changed, find another with
`SELECT LOWER(HEX(p.id)) FROM product p JOIN product_visibility pv ON pv.product_id = p.id AND pv.product_version_id = p.version_id WHERE p.active = 1 AND p.parent_id IS NULL AND p.child_count > 0 LIMIT 1;`
and use it for the rest of this task.

- [ ] **Step 3: Ask the assistant to add it**

One real model call — run it once, not in a loop:

```bash
curl -s -X POST http://127.0.0.1:8000/assistant/chat \
  -H 'Content-Type: application/json' -H 'X-Requested-With: XMLHttpRequest' \
  -d '{"message":"add the Midi Bag 0539 to my cart"}'
```

Record the assistant's `prose` verbatim. The model may resolve a variant on its own rather than calling `add_to_cart` with the family id — that is a **good** outcome and not a failed test; note which of the two happened.

- [ ] **Step 4: Read the trace rather than trusting the prose**

Query `swag_assistant_trace_event` for the conversation the response's `token` names, and report every `cart.add` stage payload. What you are looking for: either no `cart.add` at all (the model resolved a variant first), or one carrying `policyReasonCode: variant_required`. A `cart.add` with `policyVerdict: allow` and the family id would mean the guard did not fire — report that immediately and stop.

- [ ] **Step 5: Record what you saw**

Append a short "Observed on the demo shop, 2026-08-30" section to this plan file with the prose, which of the two outcomes occurred, and the trace payloads. Keep it to a few lines.

- [ ] **Step 6: Commit**

```bash
git add docs/superpowers/plans/2026-08-30-refuse-a-family-in-the-cart.md
git commit -m "docs: record the family-refusal check against the demo shop"
```

---

## Out of scope, on purpose

- **A prompt rule telling the model to ask before adding.** The guard makes guessing impossible rather than discouraged, which is this repository's stated preference — capability control is toolbox construction, never a prompt instruction. Whether a rule is still needed is a question for the traces after this lands, not before.
- **Ambiguity *within* a family** — "the blue one" when blue exists in M and L. The guard does not address it, because that card is a genuine variant and the tool cannot tell a good choice from a guess. If the traces show it biting, it needs its own treatment.
- **The `elapsedMs` defect** in `DalConversationStore::traceEvents()`, documented at the site and unrelated to this.

## Observed on the demo shop, 2026-08-30

Card endpoint for `00235daf21160538fe65da8d8fa38876` ("Midi Bag 0539") confirmed `"stockSource":"parent"` — a family, as expected.

One real model call, `"add the Midi Bag 0539 to my cart"`, produced:

> The Midi Bag 0539 comes in several sizes: XS, S, M, L, and XL.
>
> Which size would you like to add to your cart?

The trace for that conversation (token `01a053ec3dc873cd9a9aa7017f3e63d7`) shows a single tool call — `search_products` — and no `add_to_cart` call at all (`turn.end` payload: `{"outcome":"product_shown","toolCalls":1,...}`). No `cart.add` stage exists in the trace.

**Outcome: the model resolved on its own that it needed a variant and never called `add_to_cart` with the family id** — the first of the two good outcomes described in the brief. The guard was not exercised on this particular turn (there was nothing for it to refuse), but the model's own behavior on the real DAL path did not produce the failure case either. This one turn does not exercise the guard directly; a prompt that pushes the model toward calling `add_to_cart` with the family id anyway would be needed to see the guard fire on this path, and is left to a future check per the "out of scope" note above about whether a prompt rule is still needed.
