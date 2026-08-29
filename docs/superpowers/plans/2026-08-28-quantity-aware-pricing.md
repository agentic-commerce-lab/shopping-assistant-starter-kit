# Quantity-Aware Pricing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a shopper state a quantity and get the Shopware-calculated tier that applies to it, on the card and after a page reload.

**Architecture:** A requested quantity travels from the tool arguments down to `DalProductCardMapper`, which already knows how to pick the applicable tier — today it always asks for the quantity at `minPurchase`. The quantity then travels back out on the card, into the transcript as a `{productId, quantity}` reference instead of a bare id, and back in through the cards endpoint so a re-hydrated conversation keeps the basis its price was quoted at. No price is ever persisted; only the quantity that selected it.

**Tech Stack:** PHP 8.2, Shopware 6.7 (core + storefront in `vendor/`), PHPUnit 11, vanilla ES modules for the storefront widget, `node --test` for pure JS, Playwright against the local demo shop.

**Spec:** `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md` — sections 7.2 and 7.4. Section 16's sequencing items 1 and 2 are already **Done**; this plan is the next increment and deliberately contains **no** `ShoppingContext`, conversation-scoping or Commercial work.

**What already exists, and must not be rebuilt:**

- `DalApplicablePrice::forQuantity(PriceCollection $tiers, int $quantity): ?CalculatedPrice` reconstructs tier ranges and returns the applicable entry. It is correct and tested; this plan only changes *which quantity* is handed to it.
- `DalProductCardMapper::map()` already computes `$quantity = max(1, $product->getMinPurchase() ?? 1)` and reports it as `ProductCard::$priceQuantity`. This plan makes that quantity an argument with the current behaviour as the default.
- `ProductCard` already carries `priceQuantity`, `hasVolumePricing`, `minPurchase` and `purchaseSteps`.

## Global Constraints

- `php: ^8.2`; `shopware/core: ~6.7.0`; `shopware/storefront: ~6.7.0`. **No new Composer or npm dependencies.**
- Shopware types may appear only inside `src/Core/Commerce/Dal/`. No Shopware type in a signature above the gateway seam.
- `ProductCard` is an **allowlist**. Never add a passthrough array or a raw-entity property.
- **Prices are never persisted.** A transcript may store a quantity; it must never store a figure.
- The assistant performs no price arithmetic of its own — it selects among figures Shopware calculated.
- Files stay at or under **400 physical lines** (`composer quality:filesize`).
- `composer test` (excludes the eval group) must pass with **no `.env` file present**; `composer quality` must pass end to end.
- Tool result strings are English prose; storefront strings are snippets.
- mago's gates fail at error level via the pre-commit hook: **11 methods per class** and a class-scoped **cyclomatic-complexity budget of 10**. The house resolution is a sibling file or class with a docblock explaining the split — see `tests/Core/Commerce/Dal/DalProductCardMapperAdvancedPriceTest.php` and `src/Core/Tool/CartCorrectionNote.php`. Use it when a gate fires and say so in the report.
- Storefront DOM-assembly functions get no unit tests (`tests/e2e/README.md`); pure functions do.

---

### Task 1: A card reference is a product and a quantity

The transcript stores bare product ids today. A quantity-aware card needs the quantity that selected its tier, or a re-hydrated conversation silently re-prices at the minimum.

**Wire format:** `a2a2…` (32 hex, quantity 1) or `a2a2…@120`. Keeping one comma-separated `ids` parameter means the endpoint, its cap and every legacy client keep working unchanged.

**Files:**
- Create: `src/Controller/CardReference.php`
- Test: `tests/Controller/CardReferenceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final readonly class CardReference` with `public string $productId`, `public int $quantity`, `public static function fromWire(string $raw): ?self`, `public function toWire(): string`, and `public function key(): string`. Tasks 5, 6 and 7 all use these.

- [ ] **Step 1: Write the failing test**

Create `tests/Controller/CardReferenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\CardReference;

/**
 * The reference is the one thing a transcript stores about a card besides its id, so its parser is
 * the boundary between a stored conversation and a re-rendered one. Everything here is untrusted:
 * the string was written by an earlier version of this plugin, or by a client.
 */
final class CardReferenceTest extends TestCase
{
    private const ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    public function testABareIdDecodesAsQuantityOne(): void
    {
        // Every transcript written before this change holds bare ids. They must keep working, and
        // one unit is what they meant.
        $reference = CardReference::fromWire(self::ID);

        self::assertNotNull($reference);
        self::assertSame(self::ID, $reference->productId);
        self::assertSame(1, $reference->quantity);
    }

    public function testAQuantityIsCarriedThroughTheWireAndBack(): void
    {
        $reference = CardReference::fromWire(self::ID . '@120');

        self::assertNotNull($reference);
        self::assertSame(120, $reference->quantity);
        self::assertSame(self::ID . '@120', $reference->toWire());
    }

    public function testQuantityOneSerialisesWithoutTheSuffix(): void
    {
        // Keeps the common case byte-identical to what older clients send and older transcripts
        // hold, so nothing churns for a conversation that never stated a quantity.
        self::assertSame(self::ID, (new CardReference(self::ID, 1))->toWire());
    }

    public function testAMalformedReferenceIsRejectedRatherThanRepaired(): void
    {
        // This value reaches a repository lookup on a public endpoint. A guess here is a
        // guess at what a shopper's conversation referred to.
        self::assertNull(CardReference::fromWire(''));
        self::assertNull(CardReference::fromWire('not-an-id'));
        self::assertNull(CardReference::fromWire(self::ID . '@'));
        self::assertNull(CardReference::fromWire(self::ID . '@0'));
        self::assertNull(CardReference::fromWire(self::ID . '@-4'));
        self::assertNull(CardReference::fromWire(self::ID . '@abc'));
        self::assertNull(CardReference::fromWire(strtoupper(self::ID)));
    }

    public function testAnAbsurdQuantityIsRejectedRatherThanClamped(): void
    {
        // A clamp would answer a question the shopper did not ask. 10000 is far above any
        // plausible line quantity and well inside what Shopware can price.
        self::assertNull(CardReference::fromWire(self::ID . '@100001'));
        self::assertNotNull(CardReference::fromWire(self::ID . '@10000'));
    }

    public function testTheKeyDistinguishesTheSameProductAtTwoQuantities(): void
    {
        // The client maps resolved cards by this key. One product quoted at two quantities is two
        // cards, and a key that collapsed them would render one of them twice.
        self::assertNotSame(
            (new CardReference(self::ID, 1))->key(),
            (new CardReference(self::ID, 24))->key(),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Controller/CardReferenceTest.php
```

Expected: FAIL — `Class "Swag\AssistantStarterKit\Controller\CardReference" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Controller/CardReference.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

/**
 * One card a conversation referred to: a product, and the quantity its price was quoted at.
 *
 * A transcript stores references rather than cards, because a stored price is a fact frozen at
 * write time. It stores the **quantity** because that is not a fact about the catalogue — it is
 * what the shopper asked for, and without it a re-hydrated card silently re-prices at the product's
 * minimum. "EUR 70.00 each at 120 units" must still say 120 tomorrow, with today's price.
 *
 * The wire form is `<32 hex>` or `<32 hex>@<quantity>`, comma-separated in the existing `ids`
 * parameter. Quantity one omits the suffix, so every transcript and client written before this
 * existed keeps working unchanged and nothing churns for the common case.
 *
 * Parsing is total and narrowing: anything that does not fit returns null rather than a repaired
 * value. This reaches a repository lookup on a public endpoint, and a guess here is a guess about
 * what a shopper's conversation referred to.
 */
final readonly class CardReference
{
    /**
     * A Shopware id: 32 lowercase hex characters. Shared with {@see CardIdList::ID_PATTERN} because
     * a conversation token has the same shape, and one definition beats two that can drift.
     */
    public const ID_PATTERN = CardIdList::ID_PATTERN;

    /**
     * The largest quantity a reference may carry.
     *
     * Not a business rule — Shopware decides what a shopper may buy. It bounds what a public
     * endpoint will parse, and it is far above any plausible line quantity, so a value past it is
     * a malformed request rather than a large order. Rejected, never clamped: a clamp would answer
     * a question nobody asked.
     */
    public const MAX_QUANTITY = 10000;

    public function __construct(
        public string $productId,
        public int $quantity = 1,
    ) {}

    public static function fromWire(string $raw): ?self
    {
        $parts = explode('@', $raw, 2);
        $productId = $parts[0];

        if (preg_match(self::ID_PATTERN, $productId) !== 1) {
            return null;
        }

        if (!isset($parts[1])) {
            return new self($productId);
        }

        // ctype_digit rather than is_numeric: it rejects '4.0', '+4', ' 4' and '' in one call, and
        // every one of those would otherwise cast to a plausible-looking integer.
        if (!ctype_digit($parts[1])) {
            return null;
        }

        $quantity = (int) $parts[1];

        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            return null;
        }

        return new self($productId, $quantity);
    }

    public function toWire(): string
    {
        return $this->quantity === 1 ? $this->productId : $this->productId . '@' . $this->quantity;
    }

    /**
     * Identity for de-duplication and for the client's resolved-card map. One product at two
     * quantities is two cards.
     */
    public function key(): string
    {
        return $this->toWire();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Controller/CardReferenceTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/CardReference.php tests/Controller/CardReferenceTest.php
git commit -m "feat(cards): a card reference carries the quantity its price assumes"
```

---

### Task 2: The mapper prices at a requested quantity

`DalProductCardMapper::map()` hardcodes the quantity to `max(1, minPurchase ?? 1)`. It becomes an argument, with today's behaviour as the default when no quantity was requested.

**Files:**
- Modify: `src/Core/Commerce/Dal/DalProductCardMapper.php` — the `map()` signature and its quantity line
- Test: `tests/Core/Commerce/Dal/DalProductCardMapperAdvancedPriceTest.php` (new cases appended; split to a sibling file if the method-count gate fires)

**Interfaces:**
- Consumes: `DalApplicablePrice::forQuantity()`, unchanged.
- Produces: `DalProductCardMapper::map(SalesChannelProductEntity $product, StockSource $source, string $currency, ?int $requestedQuantity = null): ?ProductCard`. A null quantity keeps the minimum-purchase behaviour. Task 3 passes the argument through.

- [ ] **Step 1: Write the failing test**

Append to `tests/Core/Commerce/Dal/DalProductCardMapperAdvancedPriceTest.php`:

```php
    public function testAStatedQuantitySelectsItsTierRatherThanTheMinimums(): void
    {
        // Tiers 1-9 at 79.90, 10+ at 70.00, no minimum purchase. Asked for 12, the card must quote
        // 70.00 and say it assumes 12 — not 79.90, which is what the default (quantity one)
        // behaviour would pick.
        $product = $this->withTiers($this->product(79.90, 500), [[79.90, 9], [70.00, 10]]);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR', 12);

        self::assertNotNull($card);
        self::assertSame(70.00, $card->price);
        self::assertSame(12, $card->priceQuantity);
    }

    public function testAStatedQuantityBelowTheMinimumIsRaisedToTheMinimum(): void
    {
        // A shopper asking for 2 of a case-of-24 product cannot buy 2. Quoting the single-unit tier
        // beside a product Shopware will not sell in that quantity is the misleading figure this
        // whole area exists to prevent.
        $product = $this->withTiers($this->product(79.90, 500), [[79.90, 23], [70.00, 24]], 24);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR', 2);

        self::assertNotNull($card);
        self::assertSame(70.00, $card->price);
        self::assertSame(24, $card->priceQuantity);
    }

    public function testNoStatedQuantityKeepsTheMinimumPurchaseBehaviour(): void
    {
        // The default path, unchanged: every existing caller passes no quantity.
        $product = $this->withTiers($this->product(79.90, 500), [[79.90, 23], [70.00, 24]], 24);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR');

        self::assertNotNull($card);
        self::assertSame(24, $card->priceQuantity);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/
```

Expected: FAIL — `map()` takes three arguments, so the four-argument calls are a TypeError.

- [ ] **Step 3: Write the implementation**

In `src/Core/Commerce/Dal/DalProductCardMapper.php`, change the signature and the quantity line. Everything else in `map()` stays as it is:

```php
    /**
     * @param string   $currency          The sales channel's ISO code, passed per call rather than
     *        injected. The currency is a property of the request's sales-channel context, not of
     *        this service: a shop with two channels in different currencies would otherwise have
     *        every card in one of them labelled with the other's code. A wrong currency beside a
     *        right number is a fabricated fact, which is the one thing this pipeline exists to
     *        prevent.
     * @param int|null $requestedQuantity The quantity the shopper asked about, or null when they
     *        asked about the product rather than an amount of it. Null prices the smallest order
     *        they may actually place; a stated quantity below the product's minimum is raised to
     *        that minimum, because a price for an unbuyable amount is not an answer.
     */
    public function map(
        SalesChannelProductEntity $product,
        StockSource $source,
        string $currency,
        ?int $requestedQuantity = null,
    ): ?ProductCard {
        $minimum = max(1, $product->getMinPurchase() ?? 1);
        $quantity = max($minimum, $requestedQuantity ?? $minimum);

        // ... the rest of the method is unchanged: $tiers, $applicable, and the ProductCard
        // construction, which already reads $quantity for both the price and priceQuantity.
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
composer test
```

Expected: PASS. Every existing caller omits the new argument and keeps the previous behaviour.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Commerce/Dal/DalProductCardMapper.php \
        tests/Core/Commerce/Dal/DalProductCardMapperAdvancedPriceTest.php
git commit -m "feat(commerce): price a card at a requested quantity"
```

---

### Task 3: The gateway carries the quantity

The mapper can price at a quantity; nothing can ask it to. The gateway seam has to carry the value, because the mapper lives behind it and no caller above may hold a Shopware type.

**Files:**
- Modify: `src/Core/Commerce/Dto/ProductQuery.php` — add `quantity`
- Modify: `src/Core/Commerce/CommerceGatewayInterface.php` — `product()` gains an optional quantity
- Modify: `src/Core/Commerce/BatchProductLookup.php` — `products()` gains an optional quantity
- Modify: `src/Core/Commerce/Dal/DalCommerceGateway.php` — pass it into `mapAll()`/`map()`
- Modify: `src/Core/Commerce/FixtureCommerceGateway.php` — accept and ignore it, documented
- Test: `tests/Core/Commerce/Dal/DalCommerceGatewayQuantityTest.php` (create)

**Interfaces:**
- Consumes: `DalProductCardMapper::map(..., ?int $requestedQuantity = null)` from Task 2.
- Produces: `ProductQuery::$quantity` (`?int`, defaulted null, last constructor parameter); `CommerceGatewayInterface::product(string $productId, CatalogScope $scope, ?int $quantity = null): ?ProductCard`; `BatchProductLookup::products(array $productIds, CatalogScope $scope, ?int $quantity = null): list<ProductCard>`. Tasks 4 and 6 call these.

- [ ] **Step 1: Read the two gateway implementations end to end**

```bash
sed -n '1,120p' src/Core/Commerce/Dal/DalCommerceGateway.php
grep -n "mapAll\|->map(" src/Core/Commerce/Dal/DalCommerceGateway.php
```

You are threading one nullable int from three entry points (`search`, `product`, `products`) down to `mapAll()`. Note where `resolveVariant()` maps too — it takes no quantity in this plan, and the reason is written in Step 3.

- [ ] **Step 2: Write the failing test**

Create `tests/Core/Commerce/Dal/DalCommerceGatewayQuantityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * The quantity has to survive the gateway seam, and this pins the shape rather than the plumbing:
 * a `ProductQuery` that silently dropped its quantity would leave every search card priced at one
 * unit while the prose talked about a hundred.
 */
final class DalCommerceGatewayQuantityTest extends TestCase
{
    public function testAProductQueryCarriesAnOptionalQuantity(): void
    {
        self::assertNull((new ProductQuery(term: 'jersey'))->quantity);
        self::assertSame(120, (new ProductQuery(term: 'jersey', quantity: 120))->quantity);
    }
}
```

- [ ] **Step 3: Run it, then implement**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/DalCommerceGatewayQuantityTest.php
```

Expected: FAIL — unknown named argument `quantity`.

Then make these changes:

**`src/Core/Commerce/Dto/ProductQuery.php`** — append as the last constructor parameter:

```php
        /**
         * The quantity the shopper asked about, or null when they asked about products rather than
         * an amount. It reaches {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper}
         * and selects the applicable price tier; it does not filter, sort or otherwise change which
         * products come back.
         */
        public ?int $quantity = null,
```

**`src/Core/Commerce/CommerceGatewayInterface.php`** — `product()` becomes:

```php
    public function product(string $productId, CatalogScope $scope, ?int $quantity = null): ?ProductCard;
```

**`src/Core/Commerce/BatchProductLookup.php`** — `products()` becomes:

```php
    /**
     * @param list<string> $productIds
     *
     * @return list<ProductCard>
     */
    public function products(array $productIds, CatalogScope $scope, ?int $quantity = null): array;
```

**`src/Core/Commerce/Dal/DalCommerceGateway.php`** — thread the value through. `search()` reads `$query->quantity`; `product()` and `products()` take theirs as an argument; all three pass it to `mapAll()`, which passes it to `$this->cardMapper->map(...)` as the fourth argument. **`resolveVariant()` keeps mapping at no quantity** — add this comment there:

```php
        // No quantity: resolving "the blue one in M" is a question about which product, not about
        // how many. The tool that then quotes a price for an amount asks for it by id, with the
        // quantity attached.
```

**`src/Core/Commerce/FixtureCommerceGateway.php`** — accept the parameter on `product()` and `products()` to satisfy the interface, and document why it changes nothing:

```php
        // Accepted and ignored. The fixture catalogue has flat prices — no `calculatedPrices`, no
        // tiers — so there is no tier for a quantity to select. Silently ignoring it is right here
        // and would be a defect in the DAL gateway; the difference is that this class has no
        // price ladder to consult, not that it declines to.
```

- [ ] **Step 4: Run the whole suite**

```bash
composer test && composer quality:filesize
```

Expected: PASS. If a test double implements `CommerceGatewayInterface` or `BatchProductLookup`, it needs the new parameter — `tests/Core/Commerce/CountingBatchGateway.php`, `tests/Core/Commerce/LoopOnlyGateway.php` and the anonymous classes in `tests/Core/Tool/` are the ones to check. Adding an optional parameter to an implementing method is legal PHP, so a double may also be left alone if it does not implement the changed method.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Commerce tests/Core/Commerce
git commit -m "feat(commerce): carry a requested quantity across the gateway seam"
```

---

### Task 4: The tools accept a quantity

Now a shopper can be answered. `search_products` and `get_product` gain an optional quantity, validated the way every other tool argument is.

**Files:**
- Modify: `src/Core/Tool/SearchProductsTool.php:189-201` — signature and guard
- Modify: `src/Core/Tool/GetProductTool.php:63-66` — signature and guard
- Modify: `src/Core/Retrieval/QueryBuilder.php` — carry the quantity into `ProductQuery`
- Test: `tests/Core/Tool/ProductToolQuantityTest.php` (create)

**Interfaces:**
- Consumes: `ProductQuery::$quantity` and `CommerceGatewayInterface::product(..., ?int $quantity)` from Task 3.
- Produces: `search_products` and `get_product` accept `quantity`. Cards they return carry `priceQuantity` equal to the effective quantity. Task 5 stores it.

- [ ] **Step 1: Read how an existing numeric argument is guarded**

```bash
grep -n "boundedInt" src/Core/Tool/Guard.php src/Core/Tool/*.php
```

`Guard::boundedInt($value, $min, $max, $name)` throws `ToolArgumentException` outside the range, and `MalformedToolArgumentRejection` turns that into a message the model can act on. Follow it exactly — do not clamp.

- [ ] **Step 2: Write the failing test**

Create `tests/Core/Tool/ProductToolQuantityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\GetProductTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;

/**
 * The quantity is a model-supplied number on a tool the model calls freely, so it is guarded like
 * every other one: rejected out of range rather than clamped, because a clamp answers a question
 * the shopper did not ask and the model has no way to notice.
 */
final class ProductToolQuantityTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testAQuantityBelowOneIsRejected(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->getProductTool()(productId: 'fx-007', quantity: 0);
    }

    public function testAnAbsurdQuantityIsRejected(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->getProductTool()(productId: 'fx-007', quantity: 10001);
    }

    public function testOmittingTheQuantityStillWorks(): void
    {
        $result = $this->getProductTool()(productId: 'fx-007');

        self::assertNotSame([], $result);
    }

    public function testAValidQuantityIsAccepted(): void
    {
        $result = $this->getProductTool()(productId: 'fx-007', quantity: 120);

        self::assertNotSame([], $result);
    }

    /** Mirrors tests/Core/Tool/GetProductToolTest.php::tool(), which builds the same subject. */
    private function getProductTool(): GetProductTool
    {
        $gateway = $this->gateway();
        $trace = new TraceRecorder();

        return new GetProductTool(
            $gateway,
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }
}
```

Imports this file needs beyond the ones shown: `VariantResolver`, `BlocklistFilter`, `FactRenderer`, `TraceRecorder` — copy the `use` block from `tests/Core/Tool/GetProductToolTest.php`, which constructs the identical collaborator list.

- [ ] **Step 3: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Tool/ProductToolQuantityTest.php
```

Expected: FAIL — unknown named argument `quantity`.

- [ ] **Step 4: Implement**

**`src/Core/Tool/GetProductTool.php`** — add the parameter and guard, then pass it to the gateway lookup:

```php
    /**
     * @param int|null $quantity How many units the shopper asked about. Selects the applicable
     *        Shopware price tier; it does not add anything to a cart. Omitted means they asked
     *        about the product rather than an amount of it.
     */
    public function __invoke(string $productId, ?array $options = null, ?int $quantity = null): array
    {
        $productId = Guard::boundedString($productId, 64, 'product_id') ?? '';
        $quantity = $quantity === null
            ? null
            : Guard::boundedInt($quantity, 1, CardReference::MAX_QUANTITY, 'quantity');
```

**`src/Core/Tool/SearchProductsTool.php`** — add `?int $quantity = null` as the **last** parameter (after `$limit`, so no existing positional call shifts), guard it identically, and hand it to the query builder.

**`src/Core/Retrieval/QueryBuilder.php`** — carry the value into the `ProductQuery` it builds. Read the class first; if its `build()` signature has no room for it, pass it as an explicit argument rather than smuggling it through an existing structure.

Update both tools' `#[AsTool]` descriptions to mention the argument in one clause each — the model only knows what the description tells it. For example, on `get_product`: `Pass quantity to see the price that applies to that many units.`

- [ ] **Step 5: Run the tests**

```bash
composer test && composer quality:filesize
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Tool src/Core/Retrieval tests/Core/Tool
git commit -m "feat(tool): a shopper can ask what a quantity costs"
```

---

### Task 5: The transcript stores references, not ids

A stored conversation currently holds `cardIds: ["a2a2…"]`. It has to hold the quantity too, and must keep decoding every transcript written before today.

**Files:**
- Modify: `src/Core/Trace/ConversationTurn.php` — `$cardIds` becomes `$cardRefs`
- Modify: `src/Core/Trace/TranscriptCodec.php` — encode/decode the new shape, decode the old one
- Modify: `src/Controller/AssistantController.php:139-152` — build references from the turn's cards; `:240-247` — emit them in the history payload
- Test: `tests/Core/Trace/TranscriptCodecReferenceTest.php` (create)

**Interfaces:**
- Consumes: `CardReference` from Task 1; `ProductCard::$priceQuantity` (already exists).
- Produces: `ConversationTurn::$cardRefs` as `list<string>` of wire forms. The `/assistant/history` payload keeps the key `cardIds` and now carries wire forms in it — Task 7 reads them.

**Ruling recorded here so the implementer does not have to make it:** the history payload keeps the JSON key `cardIds` rather than gaining a new one. A bare id is a valid wire form, so an old client reading the key still gets something it understands for every turn that stated no quantity, and the alternative — two keys meaning almost the same thing — is worse for every future reader.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Trace/TranscriptCodecReferenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TranscriptCodec;

/**
 * The decoder's inputs were written by an earlier version of this plugin. Every case here is a
 * transcript shape that exists in a real database right now, or will after this change.
 */
final class TranscriptCodecReferenceTest extends TestCase
{
    private const ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    public function testATranscriptWrittenBeforeQuantitiesExistedStillDecodes(): void
    {
        // The shape every stored conversation has today: bare ids under `cardIds`.
        $turns = (new TranscriptCodec())->decodeAll([[
            'role' => ConversationTurn::ROLE_ASSISTANT,
            'prose' => 'Here it is.',
            'cardIds' => [self::ID],
            'outcome' => 'answered',
        ]]);

        self::assertCount(1, $turns);
        self::assertSame([self::ID], $turns[0]?->cardRefs);
    }

    public function testAQuantityRoundTrips(): void
    {
        $codec = new TranscriptCodec();
        $encoded = $codec->encode(new ConversationTurn(
            role: ConversationTurn::ROLE_ASSISTANT,
            prose: 'That is the price at 120.',
            cardRefs: [self::ID . '@120'],
        ));

        self::assertSame([self::ID . '@120'], $encoded['cardIds']);
        self::assertSame([self::ID . '@120'], $codec->decodeAll([$encoded])[0]?->cardRefs);
    }

    public function testTheSameProductAtTwoQuantitiesSurvivesAsTwoReferences(): void
    {
        // The case that breaks any decoder that de-duplicates by product id.
        $codec = new TranscriptCodec();
        $encoded = $codec->encode(new ConversationTurn(
            role: ConversationTurn::ROLE_ASSISTANT,
            prose: 'One, or a case of 24.',
            cardRefs: [self::ID, self::ID . '@24'],
        ));

        self::assertSame([self::ID, self::ID . '@24'], $codec->decodeAll([$encoded])[0]?->cardRefs);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Trace/TranscriptCodecReferenceTest.php
```

Expected: FAIL — `ConversationTurn` has no `cardRefs`.

- [ ] **Step 3: Implement**

Rename `ConversationTurn::$cardIds` to `$cardRefs`, keeping it `list<string>`, and document what changed:

```php
        /**
         * The cards this turn rendered, as {@see \Swag\AssistantStarterKit\Controller\CardReference}
         * wire forms — `<id>` or `<id>@<quantity>`.
         *
         * Renamed from `cardIds` on 2026-08-28. A bare id is still a valid entry and is what every
         * transcript written before that date holds, so the stored JSON key stays `cardIds` and no
         * migration is needed: the value's grammar widened, its meaning did not.
         */
        public array $cardRefs = [],
```

In `TranscriptCodec`, read and write `$turn->cardRefs` under the same `cardIds` JSON key, still through `$this->shape->strings(...)` — a stored entry that is not a string is dropped exactly as before.

In `AssistantController`, build the references from the rendered cards:

```php
                cardRefs: array_map(
                    static fn(ProductCard $card): string => (new CardReference($card->id, $card->priceQuantity))->toWire(),
                    $turn->cards,
                ),
```

and leave the history payload's `'cardIds' => $turn->cardRefs` key name alone.

- [ ] **Step 4: Run the whole suite**

```bash
composer test
```

Expected: PASS. Several tests construct `ConversationTurn` with a `cardIds:` named argument — update them to `cardRefs:`. `grep -rn "cardIds:" src tests` finds them.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat(conversation): store the quantity a card's price was quoted at"
```

---

### Task 6: The cards endpoint resolves references

`GET /assistant/cards?ids=…` parses bare ids and resolves them one price ladder at a time. It has to parse references, and group them so a re-hydration is one query per distinct quantity rather than one per card.

**Files:**
- Modify: `src/Controller/CardIdList.php` — parse into `CardReference` objects; cap counts references
- Modify: `src/Core/Commerce/CardResolver.php` — accept references, group by quantity
- Modify: `src/Controller/AssistantCardController.php` — pass references through
- Test: `tests/Controller/CardIdListTest.php` (existing, extend), `tests/Core/Commerce/CardResolverTest.php` (existing, extend)

**Interfaces:**
- Consumes: `CardReference` (Task 1); `BatchProductLookup::products(array $productIds, CatalogScope $scope, ?int $quantity = null)` (Task 3).
- Produces: `CardIdList::fromRequest(Request): list<CardReference>`; `CardResolver::resolve(array $references, CatalogScope $scope): list<ProductCard>`. Task 7 sends the wire forms these parse.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Controller/CardIdListTest.php`:

```php
    public function testAReferenceWithAQuantityIsParsed(): void
    {
        $references = CardIdList::fromRequest(new Request(['ids' => self::BLUE_M . '@120,' . self::BLACK_M]));

        self::assertCount(2, $references);
        self::assertSame(120, $references[0]?->quantity);
        self::assertSame(1, $references[1]?->quantity);
    }

    public function testTheSameProductAtTwoQuantitiesIsNotDeduplicated(): void
    {
        // De-duplication is by reference, not by product: a turn that quoted one unit and a case
        // rendered two cards, and collapsing them loses one.
        $references = CardIdList::fromRequest(new Request(['ids' => self::BLUE_M . ',' . self::BLUE_M . '@24']));

        self::assertCount(2, $references);
    }

    public function testTheCapCountsReferencesRatherThanProducts(): void
    {
        $raw = implode(',', array_map(
            static fn(int $q): string => self::BLUE_M . '@' . $q,
            range(2, 2 + CardIdList::MAX_IDS),
        ));

        self::assertCount(CardIdList::MAX_IDS, CardIdList::fromRequest(new Request(['ids' => $raw])));
    }
```

That file already defines `self::BLUE_M` (`a2a2…`) and `self::BLACK_M` (`a5a5…`) — use those in place of `self::BLUE_M` / `self::BLACK_M` above.

Append to `tests/Core/Commerce/CardResolverTest.php` a case proving the grouping:

```php
    public function testReferencesAreResolvedOneQueryPerDistinctQuantity(): void
    {
        // Four references, two quantities: two batched lookups, not four. `CountingBatchGateway`
        // wraps another gateway and exposes public `$batchCalls` / `$singleCalls` counters.
        $gateway = new CountingBatchGateway(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'),
        );

        (new CardResolver($gateway))->resolve([
            new CardReference('fx-007', 1),
            new CardReference('fx-017', 1),
            new CardReference('fx-007', 24),
            new CardReference('fx-017', 24),
        ], new CatalogScope());

        self::assertSame(2, $gateway->batchCalls);
        self::assertSame(0, $gateway->singleCalls);
    }
```

`CountingBatchGateway::products()` currently takes `(array $productIds, CatalogScope $scope)`. Task 3 widened the `BatchProductLookup` signature, so give this double the optional `?int $quantity = null` parameter too.

- [ ] **Step 2: Run them to verify they fail**

```bash
vendor/bin/phpunit tests/Controller/CardIdListTest.php tests/Core/Commerce/CardResolverTest.php
```

Expected: FAIL.

- [ ] **Step 3: Implement**

`CardIdList::parse()` maps each comma-separated entry through `CardReference::fromWire()`, drops nulls, de-duplicates **by `key()`**, preserves order, and caps at `MAX_IDS` references. Update the class docblock: the cap now bounds references, and the constant's meaning is unchanged because one reference is still one catalogue lookup's worth of work.

`CardResolver::resolve()` groups references by quantity, calls `products($ids, $scope, $quantity)` once per group when the gateway is a `BatchProductLookup` (and `product($id, $scope, $quantity)` per reference when it is not), then returns cards in the order the references were given. The existing `inRequestedOrder()` helper keys by product id — it must key by reference instead, or the same product at two quantities collapses.

- [ ] **Step 4: Run the suite**

```bash
composer test && composer quality:filesize
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controller src/Core/Commerce tests
git commit -m "feat(cards): resolve quantity-aware references, grouped by quantity"
```

---

### Task 7: The widget round-trips references

The client asks for `ids` and maps the answer by product id. Both have to become reference-aware, or a re-hydrated conversation renders one card where it rendered two.

**Files:**
- Modify: `src/Resources/app/storefront/src/assistant/transport.js:20,131` — batch by reference
- Modify: `src/Resources/app/storefront/src/assistant/panel.plugin.js:336-348` — key the resolved map by reference
- Modify: `src/Resources/app/storefront/src/assistant/card.js` — the add button submits the card's `priceQuantity`
- Test: `tests/js/card-reference.test.js` (create)

**Interfaces:**
- Consumes: the history payload's `cardIds` array of wire forms (Task 5); the cards endpoint's reference parsing (Task 6).
- Produces: nothing later depends on it.

- [ ] **Step 1: Write the failing test**

Create `tests/js/card-reference.test.js`, testing a pure helper you are about to add to `transport.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { referenceKey } from '../../src/Resources/app/storefront/src/assistant/transport.js';

/*
 * A pure function, so it gets a unit test — DOM assembly does not (tests/e2e/README.md).
 * The key is what maps a resolved card back to the reference that asked for it, and getting it
 * wrong renders the wrong card beside the wrong sentence.
 */

const ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

test('a card resolved at one unit keys to its bare id', () => {
    assert.equal(referenceKey({ id: ID, priceQuantity: 1 }), ID);
});

test('a card resolved at a quantity keys to the reference that asked for it', () => {
    assert.equal(referenceKey({ id: ID, priceQuantity: 24 }), `${ID}@24`);
});

test('a card from a server that does not send the field keys as one unit', () => {
    assert.equal(referenceKey({ id: ID }), ID);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
npm run test:js
```

Expected: FAIL — no export named `referenceKey`.

- [ ] **Step 3: Implement**

Export `referenceKey(card)` from `transport.js`, mirroring `CardReference::toWire()` exactly — bare id at quantity 1, `id@n` otherwise, and a missing `priceQuantity` treated as 1.

In `panel.plugin.js`, build `wanted` from the raw `cardIds` wire forms (no change — they are already strings), and key the resolved `Map` by `referenceKey(card)` so `message.cardIds.map((ref) => resolved.get(ref))` lines up.

In `card.js`, make the add-to-cart button submit `card.priceQuantity` rather than a hardcoded 1, so the button adds the amount the card was priced for.

- [ ] **Step 4: Run the JS and PHP suites**

```bash
npm run test:js && composer test
```

Expected: PASS.

- [ ] **Step 5: Rebuild the storefront bundle**

```bash
composer build:storefront
git status --short src/Resources/app/storefront/dist
```

Expected: the dist bundle shows as modified. If `shopware-cli` is unavailable, stop and report rather than committing a stale bundle.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/storefront tests/js/card-reference.test.js
git commit -m "feat(widget): re-hydrate a card at the quantity it was quoted for"
```

---

### Task 8: Live proof, docs, and the full gate

**Files:**
- Modify: `tests/e2e/pricing.spec.js` — one new case
- Modify: `ARCHITECTURE.md` — the `ProductCard` and gateway signatures
- Modify: `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md` — mark 7.2 and 7.4 done

**Interfaces:** consumes everything; produces nothing.

- [ ] **Step 1: Add the live assertion**

In `tests/e2e/pricing.spec.js`, add a case that requests one product at two quantities in a single call — `?ids=<id>,<id>@50` — and asserts two cards come back with different `priceQuantity` values, and that neither price is invented (both must appear in the shop's own tier list on the product page). Use the tier-priced ids the file already samples. If the shop's tiers make both quantities land in the same tier, pick a quantity from the docblock's SQL that crosses a boundary, and say in a comment which product and boundary you used.

- [ ] **Step 2: Re-run the journeys that share the two tools this plan changed**

Between this plan being written and being executed, another branch added `MatchReasons`,
`compare_products`, bounded product properties and a property-claim audit — all of which run through
`search_products` and `get_product`, the same two tools Task 4 re-signed. No code conflicts with this
plan, but their journeys now exercise the changed signatures.

```bash
vendor/bin/phpunit --group eval --filter 'compare_two_products|match_reason|property'
```

Expected: PASS. These are live model calls, so run the filter once, not the whole eval group. If one
fails, read whether it fails on the tool arguments (this plan's doing) or on model wording (not).

- [ ] **Step 3: Run everything**

```bash
composer test && composer quality && npm run test:js && \
  SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/pricing.spec.js
```

Expected: PASS throughout. The demo shop mounts this repository, so a `docker exec shopping-assistant-test-web-1 sh -lc 'php bin/console cache:clear'` may be needed first.

- [ ] **Step 4: Update the architecture record**

In `ARCHITECTURE.md`, locate the `CommerceGatewayInterface` block **by content, not line number**, and update `product()` and `products()` with their new optional quantity. Add `quantity` to the `ProductQuery` block.

- [ ] **Step 5: Mark the spec sections done**

In `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md`, prefix the headings of sections 7.2 and 7.4 with `**Done 2026-08-28.**` so the remaining plans do not re-plan this.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/pricing.spec.js ARCHITECTURE.md docs/superpowers/specs
git commit -m "docs: record quantity-aware pricing as shipped"
```

---

## Out of scope for this plan, on purpose

- Rendering a complete tier table. Spec 7.2 defers it explicitly.
- **`calculatedMaxPurchase` and pack unit on the card**, which spec 7.2 lists among a quantity-aware
  card's fields. Nothing in this plan reads them, and the last branch's final review already flagged
  adding fields to an allowlist DTO whose only production purpose is not to lie about themselves.
  They belong with the quantity pre-validation that consumes them, which is deferred with them.
- `ShoppingContext`, the resolver, conversation scoping, the browser token map. That is plan 2b.
- Anything Commercial: the employee bridge, organisation resolution, Advanced Product Catalogue enforcement. Blocked on a B2B licence — the demo instance's `commercial:feature:list` shows B2B is not included in its plan.
- Pre-validating a quantity against `minPurchase`/`purchaseSteps` before a cart call. The cart already reports what Shopware stored, which is the guarantee; the pre-check is a courtesy and belongs with the B2B work that surfaces those constraints on the card.
