# Price and Cart Truth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the assistant quote the price Shopware's own storefront quotes, and report the cart Shopware actually stored.

**Architecture:** Two independent corrections to existing shipped behavior, both inside the DAL seam. First, product cards select their unit price from `SalesChannelProductEntity::getCalculatedPrices()` — the advanced/volume price collection the storefront prefers — falling back to `getCalculatedPrice()` only when that collection is empty, and carrying the quantity the chosen price assumes so the figure is never stated without its basis. Second, `add_to_cart` reads the stored line quantity and Shopware's cart errors back out after the write, so a silently corrected quantity is reported rather than the requested one.

**Tech Stack:** PHP 8.2, Shopware 6.7 (core + storefront in `vendor/`), PHPUnit 11, vanilla ES modules for the storefront widget, `node --test` for pure JS, Playwright against the local demo shop.

**Proof layers, and what each one can and cannot show:**

| Layer | Proves | Cannot prove |
|---|---|---|
| PHPUnit (Tasks 1–5) | the selection logic, the mapper wiring, the tool's arithmetic | that a real Shopware populates `calculatedPrices` as assumed |
| Eval journey (Task 6) | the model's *prose* repeats the corrected quantity | anything about real pricing — the fixture has no tiers |
| Playwright (Task 7) | the assistant and the shop quote one price, on real data | nothing about prose; no model runs |

**Measured baseline, 2026-08-28, before any of this landed:** six of six tier-priced products in the local demo shop were quoted by the assistant at a price the shop's own product page contradicts, by factors of 1.7x to 4.8x. `tests/e2e/pricing.spec.js` records the table and currently fails.

**Spec:** `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md` — sections 7.1, 7.3, 16 (Sequencing). This plan is item 1 and item 2 of that section's sequencing list. It deliberately contains **no** B2B, Commercial, conversation-scoping or quantity-argument work; those are later plans.

## Global Constraints

- `php: ^8.2`; `shopware/core: ~6.7.0`; `shopware/storefront: ~6.7.0`. **No new Composer dependencies.**
- Shopware types may appear only inside `src/Core/Commerce/Dal/`. No Shopware type may cross the gateway seam into a signature above it (`ARCHITECTURE.md`).
- `ProductCard` is an **allowlist**, not a filtered entity. Never add a passthrough array or a raw-entity property. `purchasePrices` and custom fields must remain unreachable.
- Prices are never persisted. Cards are always re-rendered from the catalogue.
- The assistant performs no price, discount, tax or currency arithmetic of its own. It selects among figures Shopware calculated.
- Files stay at or under **400 physical lines** (`composer quality:filesize`).
- The deterministic suite is `composer test` (`phpunit --exclude-group eval`) and must pass with **no `.env` file present**.
- Tool result strings are English prose; the model localises them for the shopper. Storefront strings are snippets.
- Storefront rendering functions that assemble DOM are covered by the Playwright suite, not unit tests (`tests/e2e/README.md`). Pure functions are the documented exception and are covered by `node --test`.

---

### Task 1: Applicable-tier selection

A pure selector over Shopware's calculated tier collection. It exists on its own because the reconstruction it performs is the part everyone gets wrong, and it must be testable without an entity, a context or a database.

**Background the implementer needs.** `ProductPriceCalculator::calculateAdvancePrices()` (in `vendor/shopware/core/Content/Product/SalesChannel/Price/ProductPriceCalculator.php:100-139`) sorts a product's advanced price rows by `quantityStart`, then stores each resulting `CalculatedPrice` with `quantity = quantityEnd ?? quantityStart`. So every **bounded** tier carries its END quantity, and a final **open-ended** tier carries its START. There is no `getQuantityPrice()` helper on `PriceCollection` — check `vendor/shopware/core/Checkout/Cart/Price/Struct/PriceCollection.php` if you doubt it. The naive "first entry whose quantity is at least N" therefore returns nothing for any N above the last bounded tier, which is exactly the B2B bulk case.

The correct reconstruction needs no end quantities at all: entry *i* covers everything from `entries[i-1]->getQuantity() + 1` upward, and entry 0 covers from 1. Walk forward and keep the last entry whose range start is not above the requested quantity.

**Files:**
- Create: `src/Core/Commerce/Dal/DalApplicablePrice.php`
- Test: `tests/Core/Commerce/Dal/DalApplicablePriceTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `Swag\AssistantStarterKit\Core\Commerce\Dal\DalApplicablePrice::forQuantity(PriceCollection $tiers, int $quantity): ?CalculatedPrice` — returns `null` only for an empty collection. Task 2 depends on this exact signature.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Commerce/Dal/DalApplicablePriceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalApplicablePrice;

/**
 * The tier collection Shopware hands us is not a list of ranges — it is a list of prices each
 * tagged with `quantityEnd ?? quantityStart`. Every case here exists because a plausible reading
 * of that collection gets it wrong for some quantity a B2B shopper will actually type.
 */
final class DalApplicablePriceTest extends TestCase
{
    private function tier(float $unitPrice, int $quantity): CalculatedPrice
    {
        return new CalculatedPrice(
            $unitPrice,
            $unitPrice * $quantity,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            $quantity,
        );
    }

    /**
     * 1–9 at 79.90, 10–49 at 74.90, 50+ at 70.00 — the shape Shopware stores for a graduated
     * price with an open-ended top tier. Note the third entry carries 50, its START, because it
     * has no `quantityEnd`.
     */
    private function graduated(): PriceCollection
    {
        return new PriceCollection([
            $this->tier(79.90, 9),
            $this->tier(74.90, 49),
            $this->tier(70.00, 50),
        ]);
    }

    public function testAnEmptyCollectionSelectsNothingSoTheCallerCanFallBack(): void
    {
        self::assertNull((new DalApplicablePrice())->forQuantity(new PriceCollection(), 1));
    }

    public function testASingleTierAppliesAtEveryQuantity(): void
    {
        $tiers = new PriceCollection([$this->tier(59.90, 1)]);

        self::assertSame(59.90, (new DalApplicablePrice())->forQuantity($tiers, 1)?->getUnitPrice());
        self::assertSame(59.90, (new DalApplicablePrice())->forQuantity($tiers, 500)?->getUnitPrice());
    }

    public function testTheFirstTierAppliesBelowTheSecondTiersStart(): void
    {
        self::assertSame(79.90, (new DalApplicablePrice())->forQuantity($this->graduated(), 1)?->getUnitPrice());
        self::assertSame(79.90, (new DalApplicablePrice())->forQuantity($this->graduated(), 9)?->getUnitPrice());
    }

    public function testTheSecondTierAppliesFromItsFirstUnitToItsLast(): void
    {
        self::assertSame(74.90, (new DalApplicablePrice())->forQuantity($this->graduated(), 10)?->getUnitPrice());
        self::assertSame(74.90, (new DalApplicablePrice())->forQuantity($this->graduated(), 49)?->getUnitPrice());
    }

    public function testAQuantityAboveTheOpenEndedTiersStartStillSelectsIt(): void
    {
        // The case the obvious implementation misses. The top entry carries 50 — its START — so
        // "the first entry whose quantity is at least 120" matches nothing and a bulk order gets
        // no price at all, or worse, silently falls back to the single-unit figure.
        self::assertSame(70.00, (new DalApplicablePrice())->forQuantity($this->graduated(), 50)?->getUnitPrice());
        self::assertSame(70.00, (new DalApplicablePrice())->forQuantity($this->graduated(), 120)?->getUnitPrice());
    }

    public function testAQuantityBelowOneIsTreatedAsOneRatherThanSelectingNothing(): void
    {
        // A malformed argument must degrade to the ordinary single-unit answer, not to a null
        // price beside a product the shopper is looking at.
        self::assertSame(79.90, (new DalApplicablePrice())->forQuantity($this->graduated(), 0)?->getUnitPrice());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/DalApplicablePriceTest.php
```

Expected: FAIL — `Error: Class "Swag\AssistantStarterKit\Core\Commerce\Dal\DalApplicablePrice" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Commerce/Dal/DalApplicablePrice.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;

/**
 * Picks the advanced price that applies at a given quantity.
 *
 * `SalesChannelProductEntity::getCalculatedPrices()` is not a list of ranges, and reading it as
 * one is the defect this class exists to prevent. `ProductPriceCalculator::calculateAdvancePrices()`
 * sorts the source rows by `quantityStart` and stores each price with
 * `quantity = quantityEnd ?? quantityStart` — so a **bounded** tier carries its END and a final
 * **open-ended** tier carries its START. For a 1–9 / 10–49 / 50+ price that is `[9, 49, 50]`, and
 * "the first entry whose quantity is at least 120" matches nothing at all.
 *
 * Ends are therefore never read. Entry *i* begins one unit above entry *i-1*'s stored quantity,
 * entry 0 begins at one, and the applicable entry is the last one that has begun. A quantity above
 * a bounded final tier selects that tier rather than nothing, which is both the safe answer and the
 * one Shopware's own listing card reaches for when it takes `calculatedPrices.last`.
 *
 * Shopware has no helper for this — `PriceCollection` offers `sum()`, `getHighestTaxRule()` and no
 * quantity lookup — so it is written once, here, rather than at each call site.
 */
final readonly class DalApplicablePrice
{
    public function forQuantity(PriceCollection $tiers, int $quantity): ?CalculatedPrice
    {
        $entries = array_values($tiers->getElements());

        if ($entries === []) {
            return null;
        }

        // A quantity below one is a caller error, not a reason to hand a shopper a card with no
        // price on it. The smallest real order is one unit, so that is what it prices.
        $quantity = max(1, $quantity);

        $selected = $entries[0];

        foreach ($entries as $index => $entry) {
            if ($index === 0) {
                continue;
            }

            if ($entries[$index - 1]->getQuantity() + 1 > $quantity) {
                break;
            }

            $selected = $entry;
        }

        return $selected;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/DalApplicablePriceTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Commerce/Dal/DalApplicablePrice.php tests/Core/Commerce/Dal/DalApplicablePriceTest.php
git commit -m "feat(commerce): select the advanced price that applies at a quantity"
```

---

### Task 2: Cards quote the price the storefront quotes

The correction itself. Today `DalProductCardMapper` reads `getCalculatedPrice()` unconditionally, so every product carrying a Rule Builder or graduated price is quoted at a figure its own product page contradicts.

**Background the implementer needs.** Shopware's own templates decide it this way:

- `vendor/shopware/storefront/Resources/views/storefront/component/product/card/price-unit.html.twig:4-6` — starts from `calculatedPrice`, then **overrides** it with `calculatedPrices.last` whenever the collection is non-empty.
- `vendor/shopware/storefront/Resources/views/storefront/component/buy-widget/buy-widget-price.html.twig:83-86` — uses `calculatedPrices.first` for a single tier, renders a table for several, and reaches `calculatedPrice` only when there are none.

This task follows that precedence with **one deliberate divergence, recorded in spec section 7.1**: with several tiers and no stated quantity, the card prices the smallest order the shopper may actually place (`minPurchase`) rather than the storefront's cheapest-tier "from" figure. A grid of cards makes the "from" convention legible; a sentence beside a card does not, and this plugin's rule is that no figure appears without the basis that makes it true. That basis therefore travels on the card.

No criteria change is needed: `SalesChannelProductDefinition::processCriteria()` already adds the `prices` association at root nesting level, so `calculatedPrices` is populated on every card the plugin loads.

**Files:**
- Modify: `src/Core/Commerce/Dto/ProductCard.php` (add two fields at the end of the constructor)
- Modify: `src/Core/Commerce/Dal/DalProductCardMapper.php:22-30` (docblock), `:54-70` (the map body)
- Modify: `ARCHITECTURE.md:183-201` (the `ProductCard` block)
- Test: `tests/Core/Commerce/Dal/DalProductCardMapperTest.php` (new cases appended)

**Interfaces:**
- Consumes: `DalApplicablePrice::forQuantity(PriceCollection, int): ?CalculatedPrice` from Task 1.
- Produces: `ProductCard::$priceQuantity` (int, ≥1, the quantity `$price` assumes) and `ProductCard::$hasVolumePricing` (bool, true when more than one tier exists). Tasks 3 and 5 read both.

- [ ] **Step 1: Write the failing test**

Append these three cases to `tests/Core/Commerce/Dal/DalProductCardMapperTest.php`. `CalculatedPrice`, `CalculatedTaxCollection` and `TaxRuleCollection` are already imported there; add exactly one more:

```php
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
```

```php
    /**
     * @param list<array{float, int}> $tiers unit price and the tier's stored quantity
     */
    private function withTiers(SalesChannelProductEntity $product, array $tiers, ?int $minPurchase = null): SalesChannelProductEntity
    {
        $product->setMinPurchase($minPurchase);
        $product->setCalculatedPrices(new PriceCollection(array_map(
            static fn(array $tier): CalculatedPrice => new CalculatedPrice(
                $tier[0],
                $tier[0] * $tier[1],
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                $tier[1],
            ),
            $tiers,
        )));

        return $product;
    }

    public function testAnAdvancedPriceBeatsTheProductsOwnCalculatedPrice(): void
    {
        // The defect this test exists for: `calculatedPrice` is the product's own price at
        // quantity one, and Shopware's listing card and buy widget both override it whenever
        // `calculatedPrices` has anything in it. Quoting 79.90 beside a product page that says
        // 59.90 is the assistant contradicting the shop it lives in.
        $product = $this->withTiers($this->product(79.90, 12), [[59.90, 1]]);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR');

        self::assertSame(59.90, $card->price);
        self::assertSame(1, $card->priceQuantity);
        self::assertFalse($card->hasVolumePricing);
    }

    public function testWithNoStatedQuantityTheCardPricesTheSmallestOrderTheShopperMayPlace(): void
    {
        // minPurchase 24 with tiers 1-23 / 24+: pricing this at one unit quotes a price no
        // shopper can buy at. The card states the quantity it assumes so the figure is not
        // stated bare — see spec 7.1, the one place this deliberately differs from the
        // storefront's cheapest-tier "from" price.
        $product = $this->withTiers($this->product(79.90, 500), [[79.90, 23], [70.00, 24]], 24);

        $card = $this->mapper()->map($product, StockSource::Variant, 'EUR');

        self::assertSame(70.00, $card->price);
        self::assertSame(24, $card->priceQuantity);
        self::assertTrue($card->hasVolumePricing);
    }

    public function testAProductWithNoAdvancedPricesKeepsItsOwnCalculatedPrice(): void
    {
        // The ordinary shop, and the reason the fallback stays: a product with no rule prices
        // has an empty `calculatedPrices`, and the only figure Shopware calculated for it is
        // `calculatedPrice`.
        $card = $this->mapper()->map($this->product(74.90, 3), StockSource::Variant, 'EUR');

        self::assertSame(74.90, $card->price);
        self::assertSame(1, $card->priceQuantity);
        self::assertFalse($card->hasVolumePricing);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/DalProductCardMapperTest.php
```

Expected: FAIL — the first two cases fail on the price (`79.90` returned where `59.90` / `70.00` is expected) and all three fail on the unknown properties `priceQuantity` / `hasVolumePricing`.

Note the third case, `testAProductWithNoAdvancedPricesKeepsItsOwnCalculatedPrice`, uses a product whose `calculatedPrices` was **never set**. `SalesChannelProductEntity::$calculatedPrices` is a non-nullable typed property with no default, so reading it on such an entity throws `Error: must not be accessed before initialization`. That is not a test artefact to work around — a partially-hydrated entity must degrade, never abort a shopper's turn — and Step 3 handles it explicitly.

- [ ] **Step 3: Write the implementation**

In `src/Core/Commerce/Dto/ProductCard.php`, add two fields to the **end** of the constructor (append only — every other construction site in `src/` and `tests/` passes these positionally or omits them, so appending with defaults keeps them all compiling):

```php
        public array $properties = [],
        /**
         * The quantity the `$price` above assumes.
         *
         * A graduated product priced at its `minPurchase` is quoted honestly only if the quantity
         * that unlocks the figure travels with it. One means "per unit, no minimum worth stating".
         */
        public int $priceQuantity = 1,
        /**
         * Whether Shopware calculated more than one price tier for this product.
         *
         * The card says so rather than implying its figure is the only one, and never computes
         * what the other tiers cost.
         */
        public bool $hasVolumePricing = false,
    ) {}
```

In `src/Core/Commerce/Dal/DalProductCardMapper.php`, replace numbered docblock point 1 (currently the paragraph beginning "**Price comes from `getCalculatedPrice()`, never a raw price column.**") with:

```
 * 1. **Price comes from the tier that applies, not from `getCalculatedPrice()`.** That accessor
 *    returns the product's own price at quantity one. Rule Builder prices, customer-group prices
 *    expressed as rules, and every volume tier live in `calculatedPrices`, and Shopware's own
 *    storefront prefers that collection whenever it is non-empty — the listing card overrides
 *    `calculatedPrice` with `calculatedPrices.last`, the buy widget takes `calculatedPrices.first`
 *    for a single tier. Until 2026-08-28 this class read `getCalculatedPrice()` unconditionally and
 *    claimed the DAL had "already resolved customer group and rule prices there". It had not, and
 *    every rule-priced product was quoted at a figure its own product page contradicted.
 *    `calculatedPrice` remains the fallback, and is correct only when no advanced price exists.
```

Then replace the `map()` method and add the two helpers:

```php
    public function __construct(
        private ProductUrlResolver $urls,
        private PropertyGroupOptionReader $options = new PropertyGroupOptionReader(),
        private DalApplicablePrice $prices = new DalApplicablePrice(),
    ) {}

    /**
     * @param string $currency The sales channel's ISO code, passed per call rather than injected.
     *        The currency is a property of the request's sales-channel context, not of this
     *        service: a shop with two channels in different currencies would otherwise have every
     *        card in one of them labelled with the other's code. A wrong currency beside a right
     *        number is a fabricated fact, which is the one thing this pipeline exists to prevent.
     */
    public function map(SalesChannelProductEntity $product, StockSource $source, string $currency): ProductCard
    {
        // The smallest order this shopper may actually place. Pricing a case-of-24 product at one
        // unit quotes a figure nobody can buy at; see spec 7.1 for why this differs from the
        // storefront's cheapest-tier "from" price.
        $quantity = max(1, $product->getMinPurchase() ?? 1);
        $tiers = self::tiers($product);
        $applicable = $this->prices->forQuantity($tiers, $quantity);

        return new ProductCard(
            id: $product->getId(),
            parentId: $product->getParentId(),
            name: $this->inherited($product->getTranslation('name'), $product->getName()) ?? '',
            description: $this->inherited($product->getTranslation('description'), $product->getDescription()),
            price: $applicable?->getUnitPrice() ?? $product->getCalculatedPrice()->getUnitPrice(),
            currency: $currency,
            stock: $product->getStock(),
            stockSource: $source,
            deliveryTime: $product->getDeliveryTime()?->getName(),
            url: $this->urls->urlFor($product->getId()),
            imageUrl: $product->getCover()?->getMedia()?->getUrl(),
            options: $this->options->singleValued($product->getOptions()),
            categoryPath: [],
            properties: $this->options->multiValued($product->getProperties()),
            priceQuantity: $quantity,
            hasVolumePricing: $tiers->count() > 1,
        );
    }

    /**
     * The product's calculated tiers, or an empty collection.
     *
     * `SalesChannelProductEntity::$calculatedPrices` is a non-nullable typed property with **no
     * default**, so reading it on an entity the price calculator never touched throws an `Error`
     * rather than returning null. In the shop that cannot happen — `ProductSubscriber` calculates
     * on every `sales_channel.product.loaded` — but a partially hydrated entity must degrade to
     * "no advanced prices" instead of ending a shopper's turn (rulings R48, R49).
     */
    private static function tiers(SalesChannelProductEntity $product): PriceCollection
    {
        try {
            return $product->getCalculatedPrices();
        } catch (\Error) {
            return new PriceCollection();
        }
    }
```

Add the two imports the file now needs:

```php
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
```

(`DalApplicablePrice` is in the same namespace and needs no import.)

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/
```

Expected: PASS. The pre-existing `testItReadsTheVariantsOwnCalculatedPriceRatherThanARawColumn` must still pass — its product has no advanced prices, so the fallback governs it.

- [ ] **Step 5: Update the architecture record**

In `ARCHITECTURE.md`, inside the `final readonly class ProductCard` block at line 183, append the two fields after `$properties` so the document matches the type:

```php
        /** @var array<string,string[]> */ public array $properties,
        public int $priceQuantity,               // the quantity $price assumes; 1 unless minPurchase says otherwise
        public bool $hasVolumePricing,           // Shopware calculated more than one tier
```

- [ ] **Step 6: Run the whole suite and the size gate**

```bash
composer test && composer quality:filesize
```

Expected: PASS, no file over 400 lines.

- [ ] **Step 7: Commit**

```bash
git add src/Core/Commerce/Dto/ProductCard.php src/Core/Commerce/Dal/DalProductCardMapper.php \
        tests/Core/Commerce/Dal/DalProductCardMapperTest.php ARCHITECTURE.md
git commit -m "fix(commerce): quote the advanced price the storefront quotes"
```

---

### Task 3: The widget states what the price assumes

The server now knows the basis; the shopper cannot see it yet. A card reading `EUR 70.00` for a product sold in cases of 24 has replaced one misleading figure with another.

**Files:**
- Modify: `src/Controller/CardPayload.php` (two keys)
- Modify: `src/Resources/app/storefront/src/assistant/render.js` (add `formatPriceBasis`, exported beside `formatPrice`)
- Modify: `src/Resources/app/storefront/src/assistant/card.js:132-146` (`buildFacts`)
- Modify: `src/Resources/snippet/swag-assistant.en.json`, `src/Resources/snippet/swag-assistant.de.json` (two keys under `swagAssistant.card`)
- Modify: `src/Resources/views/storefront/component/assistant/panel.html.twig:104-120` (two translation entries)
- Test: `tests/js/price-basis.test.js`

**Interfaces:**
- Consumes: `ProductCard::$priceQuantity`, `ProductCard::$hasVolumePricing` from Task 2.
- Produces: wire keys `priceQuantity: int` and `hasVolumePricing: bool` on every card object; `formatPriceBasis(card, locale, translations): string` exported from `render.js`.

- [ ] **Step 1: Write the failing test**

Create `tests/js/price-basis.test.js`:

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatPriceBasis } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests, because such tests pass by restating
 * the code they check. This is the exception it names around: a pure string function whose
 * interesting cases — a case-of-24 product, a missing currency, a quantity the server did not send
 * — are exactly the ones a browser check would never think to set up.
 */

const translations = { priceAt: '%price% each at %count% units' };

test('a single-unit price is stated plainly', () => {
    const card = { price: 79.9, currency: 'EUR', priceQuantity: 1 };

    assert.equal(formatPriceBasis(card, 'en-GB', translations), '€79.90');
});

test('a price that assumes a minimum order says so', () => {
    // 70.00 is only obtainable at 24 units. Printing it bare is the same class of defect as
    // printing the single-unit price was.
    const card = { price: 70, currency: 'EUR', priceQuantity: 24 };

    assert.equal(formatPriceBasis(card, 'en-GB', translations), '€70.00 each at 24 units');
});

test('a card with no usable price renders nothing rather than a stray label', () => {
    assert.equal(formatPriceBasis({ price: null, currency: 'EUR', priceQuantity: 24 }, 'en-GB', translations), '');
    assert.equal(formatPriceBasis({ price: 70, currency: '', priceQuantity: 24 }, 'en-GB', translations), '');
});

test('a card from before this field existed is treated as a single unit', () => {
    // A transcript re-hydrated by an older cached bundle, or any client that omits the key.
    const card = { price: 79.9, currency: 'EUR' };

    assert.equal(formatPriceBasis(card, 'en-GB', translations), '€79.90');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm run test:js
```

Expected: FAIL — `SyntaxError: The requested module ... does not provide an export named 'formatPriceBasis'`.

- [ ] **Step 3: Write the implementation**

In `src/Resources/app/storefront/src/assistant/render.js`, directly below the existing `formatPrice` (line 41-47), add:

```js
/**
 * The price with the quantity it assumes, when that quantity is worth stating.
 *
 * The server chooses the figure; this only refuses to print it bare. A graduated product priced at
 * its minimum order quantity is a true number attached to a condition, and dropping the condition
 * makes it a false one.
 *
 * @param {{price?: number, currency?: string, priceQuantity?: number}} card
 * @param {string} locale
 * @param {{priceAt?: string}} translations
 * @returns {string} the formatted price, or '' when there is nothing honest to print
 */
export function formatPriceBasis(card, locale, translations = {}) {
    const price = formatPrice(card?.price, card?.currency, locale);

    if (price === '') {
        return '';
    }

    // A card written before this field existed, or a client that omits it, means one unit — never
    // a guess at what the minimum might have been.
    const quantity = Number.isInteger(card?.priceQuantity) ? card.priceQuantity : 1;

    if (quantity <= 1) {
        return price;
    }

    return (translations.priceAt ?? '%price% each at %count% units')
        .replace('%price%', price)
        .replace('%count%', String(quantity));
}
```

In `src/Resources/app/storefront/src/assistant/card.js`, replace line 9

```js
import { formatPrice } from './render';
```

with

```js
import { formatPriceBasis } from './render';
```

(`formatPrice` has no other caller in that file — the only use is the one being replaced below), then replace the price lines inside `buildFacts`:

```js
    const price = formatPriceBasis(card, locale, translations);
    if (price !== '') {
        facts.appendChild(text('p', 'swag-assistant-card__price', price));
    }

    // Said once, next to the figure it qualifies. The card never computes what the other tiers
    // cost — only the server may state a price, and it has stated the one that applies.
    if (card.hasVolumePricing && translations.volumePricing) {
        facts.appendChild(text('p', 'swag-assistant-card__price-note', translations.volumePricing));
    }
```

In `src/Controller/CardPayload.php`, add two keys after `'currency' => $card->currency,`:

```php
            // The quantity the price above assumes, and whether cheaper tiers exist. Both come
            // from the rendered card, like every other figure here — nothing is parsed out of the
            // model's prose (ruling R47).
            'priceQuantity' => $card->priceQuantity,
            'hasVolumePricing' => $card->hasVolumePricing,
```

In `src/Resources/snippet/swag-assistant.en.json`, inside `swagAssistant.card`:

```json
      "priceAt": "%price% each at %count% units",
      "volumePricing": "Lower unit prices at higher quantities",
```

In `src/Resources/snippet/swag-assistant.de.json`, inside `swagAssistant.card`:

```json
      "priceAt": "%price% pro Stück bei %count% Stück",
      "volumePricing": "Günstiger bei größeren Mengen",
```

In `src/Resources/views/storefront/component/assistant/panel.html.twig`, inside the `swag_assistant_translations` JSON block, beside the other `card.*` entries:

```twig
                    priceAt: 'swagAssistant.card.priceAt'|trans,
                    volumePricing: 'swagAssistant.card.volumePricing'|trans,
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
npm run test:js && composer test
```

Expected: PASS both. `composer test` covers `tests/PluginManifestTest.php`, which checks the snippet files parse and stay in sync — if it complains about a key present in one language and missing in the other, fix the missing one rather than deleting the key.

- [ ] **Step 5: Rebuild the storefront bundle**

The repository ships a built bundle at `src/Resources/app/storefront/dist/storefront/js/swag-assistant-starter-kit/`, so a source-only change would not reach a shop.

```bash
composer build:storefront
git status --short src/Resources/app/storefront/dist
```

Expected: the dist bundle shows as modified. If `shopware-cli` is unavailable in this environment, stop and report it rather than committing a source change whose built artefact is stale.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/CardPayload.php src/Resources/app/storefront/src src/Resources/app/storefront/dist \
        src/Resources/snippet src/Resources/views/storefront/component/assistant/panel.html.twig \
        tests/js/price-basis.test.js
git commit -m "feat(widget): state the quantity a card's price assumes"
```

---

### Task 4: The cart summary carries Shopware's corrections

Shopware does not refuse a bad quantity — it changes it and records why. `DalCartSummariser` currently discards that record entirely.

**Background the implementer needs.** `ProductCartProcessor::validateStock()` (`vendor/shopware/core/Content/Product/Cart/ProductCartProcessor.php:250-306`) does four things and reports each as a cart error rather than a failure:

| Condition | What happens to the line | Error | `getMessageKey()` |
|---|---|---|---|
| Nothing available | **Removed from the cart** | `ProductOutOfStockError` | `product-out-of-stock` |
| Stock below requested | Reduced to what is available | `ProductStockReachedError` | `product-stock-reached` |
| Below `minPurchase` | Raised to `minPurchase` | `MinOrderQuantityError` | `min-order-quantity` |
| Off `purchaseSteps` | Rounded onto the step | `PurchaseStepsError` | `purchase-steps-quantity` |

All four are constructed with the line's **`referencedId`** — the variant id — as their `$id`, and all four implement `getId()` as `$this->getMessageKey() . $this->id`. That concatenation is how a notice is attributed to a variant.

**Files:**
- Create: `src/Core/Commerce/Dto/CartNoticeReason.php`
- Create: `src/Core/Commerce/Dto/CartNotice.php`
- Modify: `src/Core/Commerce/Dto/CartSummary.php` (one field, appended)
- Modify: `src/Core/Commerce/Dal/DalCartSummariser.php`
- Modify: `ARCHITECTURE.md:259-267` (the `CartSummary` block)
- Test: `tests/Core/Commerce/Dal/DalCartSummariserTest.php` (new cases appended)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `CartSummary::$notices` (`list<CartNotice>`, default `[]`); `CartNotice` with public `string $variantId` and `CartNoticeReason $reason`; `CartNoticeReason::fromMessageKey(string): self`. Task 5 reads all three.

- [ ] **Step 1: Write the failing test**

Append to `tests/Core/Commerce/Dal/DalCartSummariserTest.php`, adding exactly these three imports:

```php
use Shopware\Core\Content\Product\Cart\MinOrderQuantityError;
use Shopware\Core\Content\Product\Cart\ProductOutOfStockError;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
```

`Cart::addErrors()` is variadic (`addErrors(Error ...$errors)`), so each case adds its error after building the cart.

```php
    public function testAQuantityShopwareCorrectedIsReportedAsANoticeAgainstItsVariant(): void
    {
        // Shopware raises an under-minimum line to minPurchase and records the reason instead of
        // refusing the add. Discarding that record is how "Added 10 to the cart" gets said about a
        // cart holding 24.
        $cart = $this->cart(1677.60, $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 24, 69.90));
        $cart->addErrors(new MinOrderQuantityError(self::BLACK_M_ID, 'Trail Jersey', 24));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        $notice = $summary->notices[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame(self::BLACK_M_ID, $notice->variantId);
        self::assertSame(CartNoticeReason::MinimumQuantity, $notice->reason);
    }

    public function testAnErrorThisPluginDoesNotKnowIsCarriedRatherThanDropped(): void
    {
        // An unknown reason still means "the cart is not what was asked for". Dropping it would
        // let the tool report a clean add over a cart Shopware complained about.
        $cart = $this->cart(0.0);
        $cart->addErrors(new ProductOutOfStockError(self::BLACK_M_ID, 'Trail Jersey'));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        self::assertSame(CartNoticeReason::OutOfStock, $summary->notices[0]?->reason);
    }

    public function testACleanCartCarriesNoNotices(): void
    {
        $summary = (new DalCartSummariser())->summarise(
            $this->cart(139.80, $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 2, 69.90)),
            'EUR',
            '/checkout/confirm',
        );

        self::assertSame([], $summary->notices);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/DalCartSummariserTest.php
```

Expected: FAIL — `Class "Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Commerce/Dto/CartNoticeReason.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * Why Shopware's cart differs from what was asked for.
 *
 * Shopware reports these as cart errors carrying human message strings; this maps the stable
 * message keys onto a closed set so a tool can explain the difference without ever putting
 * Shopware's own English sentence in front of a shopper who is reading German.
 *
 * `Other` is not a failure of this enum. An unrecognised key still means the cart is not what was
 * requested, and saying "the shop adjusted this" is honest; inventing a specific reason is not.
 */
enum CartNoticeReason: string
{
    case MinimumQuantity = 'minimum_quantity';
    case PurchaseSteps = 'purchase_steps';
    case StockLimited = 'stock_limited';
    case OutOfStock = 'out_of_stock';
    case Other = 'other';

    public static function fromMessageKey(string $messageKey): self
    {
        return match ($messageKey) {
            'min-order-quantity' => self::MinimumQuantity,
            'purchase-steps-quantity' => self::PurchaseSteps,
            'product-stock-reached' => self::StockLimited,
            'product-out-of-stock' => self::OutOfStock,
            default => self::Other,
        };
    }
}
```

Create `src/Core/Commerce/Dto/CartNotice.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One difference between the cart that was asked for and the cart that exists.
 *
 * Attributed to a variant, because a cart carries errors for every line and a tool that has just
 * added one product must not explain its own result with another line's problem.
 */
final readonly class CartNotice
{
    public function __construct(
        public string $variantId,
        public CartNoticeReason $reason,
    ) {}
}
```

In `src/Core/Commerce/Dto/CartSummary.php`, append one field:

```php
final readonly class CartSummary
{
    /**
     * @param list<CartLine>   $lineItems
     * @param list<CartNotice> $notices where this cart differs from what was requested
     */
    public function __construct(
        public array $lineItems = [],
        public float $total = 0.0,
        public string $currency = 'EUR',
        public int $itemCount = 0,
        public string $checkoutUrl = '/checkout/confirm',
        public array $notices = [],
    ) {}
}
```

In `src/Core/Commerce/Dal/DalCartSummariser.php`, extend the class docblock with a second bullet and add the notice mapping:

```php
    private function summarise(Cart $cart, string $currency, string $checkoutUrl): CartSummary
    {
        // ... existing line loop unchanged ...

        return new CartSummary(
            lineItems: $lines,
            total: $cart->getPrice()->getTotalPrice(),
            currency: $currency,
            itemCount: $itemCount,
            checkoutUrl: $checkoutUrl,
            notices: self::notices($cart),
        );
    }

    /**
     * Shopware's own complaints about this cart.
     *
     * `ProductCartProcessor` does not refuse a quantity it dislikes — it raises an under-minimum
     * line to `minPurchase`, rounds an off-step line onto its step, caps a line at available stock
     * and **removes** a line with nothing available, recording each as a cart error. Until
     * 2026-08-28 this class read only the line items, so a caller could report the quantity it had
     * asked for over a cart holding something else.
     *
     * The variant is recovered from the error id: all four of those errors are constructed with the
     * line's `referencedId` and implement `getId()` as `getMessageKey() . $id`, so stripping the key
     * leaves the variant. An error shaped otherwise yields an empty variant id rather than a wrong
     * one — an unattributed notice is still true; a misattributed one is not.
     *
     * @return list<CartNotice>
     */
    private static function notices(Cart $cart): array
    {
        $notices = [];

        foreach ($cart->getErrors() as $error) {
            $key = $error->getMessageKey();
            $id = $error->getId();

            $notices[] = new CartNotice(
                variantId: str_starts_with($id, $key) ? substr($id, \strlen($key)) : '',
                reason: CartNoticeReason::fromMessageKey($key),
            );
        }

        return $notices;
    }
```

Add the imports `CartNotice` and `CartNoticeReason` to the file.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Core/Commerce/Dal/DalCartSummariserTest.php
```

Expected: PASS, all pre-existing cases included.

- [ ] **Step 5: Update the architecture record**

In `ARCHITECTURE.md`, inside the `final readonly class CartSummary` block at line 259, append:

```php
        /** @var CartNotice[] */ public array $notices,   // where Shopware's cart differs from the request
```

- [ ] **Step 6: Commit**

```bash
git add src/Core/Commerce/Dto/CartNotice.php src/Core/Commerce/Dto/CartNoticeReason.php \
        src/Core/Commerce/Dto/CartSummary.php src/Core/Commerce/Dal/DalCartSummariser.php \
        tests/Core/Commerce/Dal/DalCartSummariserTest.php ARCHITECTURE.md
git commit -m "feat(commerce): carry Shopware's cart corrections through the gateway"
```

---

### Task 5: `add_to_cart` reports what Shopware stored

The last link. The tool still says `Added 10 to the cart` when the cart holds 8, because it formats its own argument.

**Background the implementer needs.** The tool already reads the live cart *before* the add, into `$existingQuantity`, to enforce `maxItemQuantity`. That value is exactly what makes the after-reading meaningful: what Shopware accepted is `storedLineQuantity - $existingQuantity`. Both the DAL gateway and `FixtureCommerceGateway::addToCart()` return the whole cart, so this works identically under test and in the shop.

The note text is English on purpose — every other note in this class is, and the model localises it for the shopper.

The fixture catalogue this suite runs against uses ids of the form `fx-026-blue-l`, **not** 32-character hex — a hex id would make every case below fail with "No such product in this shop" rather than on the behavior under test. The existing cases in this file also call the tool with named arguments; match that.

**Files:**
- Modify: `src/Core/Tool/AddToCartTool.php` (the tail of `__invoke`, plus three private helpers)
- Test: `tests/Core/Tool/AddToCartToolTest.php` (new cases appended)

**Interfaces:**
- Consumes: `CartSummary::$notices`, `CartNotice::$variantId`, `CartNotice::$reason`, `CartNoticeReason` from Task 4.
- Produces: no new public surface. The trace payload for stage `cart.add` gains `storedQuantity: int` beside the existing `quantity: int`, which keeps meaning *requested*.

- [ ] **Step 1: Write the failing test**

Append to `tests/Core/Tool/AddToCartToolTest.php`. It already imports `CommerceGatewayInterface`, `CartSummary`, `CatalogScope`, `FacetSet`, `ProductCard`, `ProductQuery`, `FixtureCommerceGateway`, `FactRenderer`, `AssistantConfig`, `BlocklistFilter`, `AddToCartTool` and `TraceRecorder`; add exactly these four:

```php
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNotice;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
```

`VariantSelection` is needed only for the `resolveVariant()` docblock on the anonymous class below. The interface has exactly six methods — `facets`, `search`, `product`, `resolveVariant`, `addToCart`, `cart` — and the anonymous class must implement all six.

```php
    /**
     * A gateway that behaves the way Shopware does: it accepts the add, stores a quantity of its
     * own choosing, and reports why. `FixtureCommerceGateway` cannot express that — it stores what
     * it is given — and that gap is precisely why this defect survived so long.
     */
    private function correctingGateway(int $stores, CartNoticeReason $reason): CommerceGatewayInterface
    {
        $inner = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        return new class($inner, $stores, $reason) implements CommerceGatewayInterface {
            public function __construct(
                private readonly CommerceGatewayInterface $inner,
                private readonly int $stores,
                private readonly CartNoticeReason $reason,
            ) {}

            public function facets(CatalogScope $scope): FacetSet
            {
                return $this->inner->facets($scope);
            }

            /** @return list<ProductCard> */
            public function search(ProductQuery $query, CatalogScope $scope): array
            {
                return $this->inner->search($query, $scope);
            }

            public function product(string $productId, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->product($productId, $scope);
            }

            /** @param list<VariantSelection> $selections */
            public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
            {
                return $this->inner->resolveVariant($parentId, $selections, $scope);
            }

            public function addToCart(string $variantId, int $quantity): CartSummary
            {
                $card = $this->inner->product($variantId, new CatalogScope());

                return new CartSummary(
                    lineItems: [new CartLine(
                        lineId: $variantId,
                        variantId: $variantId,
                        name: $card?->name ?? '',
                        quantity: $this->stores,
                        unitPrice: $card?->price ?? 0.0,
                        lineTotal: ($card?->price ?? 0.0) * $this->stores,
                    )],
                    total: ($card?->price ?? 0.0) * $this->stores,
                    itemCount: $this->stores,
                    notices: [new CartNotice($variantId, $this->reason)],
                );
            }

            public function cart(): CartSummary
            {
                return new CartSummary();
            }
        };
    }

    private function toolWith(CommerceGatewayInterface $gateway): AddToCartTool
    {
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new AddToCartTool($gateway, new BlocklistFilter(), $this->renderer, $this->trace, new AssistantConfig());
    }

    public function testTheNoteReportsTheQuantityShopwareStoredRatherThanTheOneRequested(): void
    {
        // A product sold in fours, asked for in tens. Shopware rounds to eight and says so; the
        // shopper who is told "Added 10" finds out at checkout.
        $tool = $this->toolWith($this->correctingGateway(8, CartNoticeReason::PurchaseSteps));

        $result = $tool(variantId: 'fx-026-blue-l', quantity: 10);

        self::assertStringContainsString('8', $result['note']);
        self::assertStringNotContainsString('Added 10', $result['note']);
        self::assertStringContainsString('steps', $result['note']);
    }

    public function testALineShopwareRefusedEntirelyIsNotReportedAsAnAdd(): void
    {
        // `ProductOutOfStockError` removes the line. Nothing was added, and the reply must say so
        // rather than confirming an add that did not happen.
        $tool = $this->toolWith($this->correctingGateway(0, CartNoticeReason::OutOfStock));

        $result = $tool(variantId: 'fx-026-blue-l', quantity: 2);

        self::assertStringContainsString('Nothing was added', $result['note']);
    }

    public function testAnUncorrectedAddStillReadsAsBefore(): void
    {
        $result = $this->tool()(variantId: 'fx-026-blue-l', quantity: 2);

        self::assertSame('Added 2 to the cart.', $result['note']);
    }

    public function testTheTraceRecordsBothTheRequestedAndTheStoredQuantity(): void
    {
        // A merchant reading the trace must be able to see the divergence that the shopper was
        // told about, without inferring it from prose.
        $tool = $this->toolWith($this->correctingGateway(8, CartNoticeReason::PurchaseSteps));
        $tool(variantId: 'fx-026-blue-l', quantity: 10);

        $payloads = [];
        foreach ($this->trace->events() as $event) {
            if ($event->stage === AddToCartTool::TRACE_STAGE) {
                $payloads[] = $event->payload;
            }
        }

        self::assertNotSame([], $payloads);
        $last = $payloads[\count($payloads) - 1];
        self::assertSame(10, $last['quantity'] ?? null);
        self::assertSame(8, $last['storedQuantity'] ?? null);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Tool/AddToCartToolTest.php
```

Expected: FAIL — the note reads `Added 10 to the cart.` where `8` was expected, and `storedQuantity` is absent from the trace payload.

- [ ] **Step 3: Write the implementation**

In `src/Core/Tool/AddToCartTool.php`, replace the block from `$cart = $this->gateway->addToCart(...)` to the end of `__invoke`:

```php
        $cart = $this->gateway->addToCart($variantId, $quantity);

        // What Shopware ACCEPTED, not what was asked for. `ProductCartProcessor` raises an
        // under-minimum line to `minPurchase`, rounds an off-step line onto its step, caps a line
        // at available stock and removes a line with nothing available — recording each as a cart
        // error rather than refusing the call. Reporting the argument instead of the result told a
        // shopper "Added 10 to the cart" over a cart holding 8, and they found out at checkout.
        //
        // The difference, not the line total: the shopper may already have had some of this
        // variant, and `$existingQuantity` was read from the live cart before the write.
        $stored = max(0, self::lineQuantity($cart, $variantId) - $existingQuantity);

        $this->renderer->registerRetrieved($filtered['cards']);

        $this->trace->record(self::TRACE_STAGE, [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => $variantId,
            // Requested, and kept under its original key so existing trace readers do not shift
            // meaning underneath them. What the cart holds is the new key beside it.
            'quantity' => $quantity,
            'storedQuantity' => $stored,
        ]);

        return [
            'cart' => [
                'itemCount' => $cart->itemCount,
                'total' => $cart->total,
                'currency' => $cart->currency,
                'checkoutUrl' => $cart->checkoutUrl,
            ],
            'note' => self::note($stored, $quantity, self::reasonFor($cart, $variantId)),
        ];
    }

    private static function lineQuantity(CartSummary $cart, string $variantId): int
    {
        foreach ($cart->lineItems as $line) {
            if ($line->variantId === $variantId) {
                return $line->quantity;
            }
        }

        return 0;
    }

    private static function reasonFor(CartSummary $cart, string $variantId): ?CartNoticeReason
    {
        foreach ($cart->notices as $notice) {
            // An unattributed notice ('' variant id) is not claimed for this variant: a cart
            // carries every line's complaints, and explaining one product's result with another
            // product's problem is a new lie in place of the old one.
            if ($notice->variantId === $variantId) {
                return $notice->reason;
            }
        }

        return null;
    }

    /**
     * English on purpose, like every other note here: the model localises it for the shopper.
     */
    private static function note(int $stored, int $requested, ?CartNoticeReason $reason): string
    {
        if ($stored === $requested) {
            return sprintf('Added %d to the cart.', $stored);
        }

        if ($stored === 0) {
            return sprintf('Nothing was added%s.', self::because($reason));
        }

        return sprintf('Added %d rather than %d%s.', $stored, $requested, self::because($reason));
    }

    private static function because(?CartNoticeReason $reason): string
    {
        return match ($reason) {
            CartNoticeReason::MinimumQuantity => ': this product has a minimum order quantity',
            CartNoticeReason::PurchaseSteps => ': this product is sold in fixed steps',
            CartNoticeReason::StockLimited => ': that is all the stock there is',
            CartNoticeReason::OutOfStock => ': the product is out of stock',
            // Including null. Something changed the quantity and this code does not know what;
            // saying so beats naming a reason it did not observe.
            default => '; the shop adjusted the quantity',
        };
    }
```

Add imports for `CartNotice`'s enum and the summary type:

```php
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
```

Leave `existingLineQuantity()` as it is — it reads the pre-add cart through the gateway and is a different question from `lineQuantity()`, which reads a summary already in hand.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Core/Tool/
```

Expected: PASS, including the pre-existing `AddToCartToolRenderingTest`.

- [ ] **Step 5: Verify the file is still within the size gate**

```bash
composer quality:filesize
```

Expected: PASS. `AddToCartTool.php` grows from 211 lines to roughly 265; the limit is 400.

- [ ] **Step 6: Commit**

```bash
git add src/Core/Tool/AddToCartTool.php tests/Core/Tool/AddToCartToolTest.php
git commit -m "fix(cart): report the quantity Shopware stored, not the one requested"
```

---

### Task 6: An eval proves the corrected quantity reaches the shopper

Task 5 proves the tool returns the right note. It does not prove the assistant *says* it — a model handed `Added 8 rather than 10: this product is sold in fixed steps` can still reply "I've added 10 for you". That is what the eval layer is for, and it needs a fixture product Shopware would correct.

**Background the implementer needs.** `FixtureCommerceGateway::addToCart()` stores exactly the quantity it is given (`src/Core/Commerce/FixtureCommerceGateway.php:162-182`). It therefore cannot reproduce Shopware's behavior, which is why no fixture test could ever have caught this defect. Giving the fixture the same two constraints Shopware enforces closes that gap permanently.

`fx-021 Disc Brake Pad Set (Shimano)` (stock 18, no variants) is the product to constrain: no journey references it — confirm with `grep -rn "fx-021" tests/ src/` before editing, and pick another unreferenced id (`fx-001`, `fx-011`, `fx-019`, `fx-031`) if that has changed.

**Files:**
- Modify: `src/Core/Commerce/Fixture/FixtureIndex.php` (optional `minPurchase` / `purchaseSteps` on a fixture unit, plus the `@phpstan-type` blocks at the top)
- Modify: `src/Core/Commerce/FixtureCommerceGateway.php:162-182` (`addToCart` applies them and emits a `CartNotice`)
- Modify: `tests/Fixtures/catalog.json` (constrain `fx-021`)
- Create: `tests/Journeys/cart_quantity_corrected.php`
- Test: `tests/Core/Commerce/FixtureCommerceGatewayTest.php` (new case)

**Interfaces:**
- Consumes: `CartNotice`, `CartNoticeReason` from Task 4; the corrected note from Task 5.
- Produces: fixture parity with Shopware's quantity correction. Nothing later depends on it.

- [ ] **Step 1: Write the failing deterministic test**

Append to `tests/Core/Commerce/FixtureCommerceGatewayTest.php`:

```php
    public function testTheFixtureCorrectsAQuantityTheSameWayShopwareDoes(): void
    {
        // Not decoration: the fixture gateway stored whatever it was handed, so every eval and
        // every fixture test agreed with a tool that was misreporting quantities. A fake that
        // cannot reproduce the defect cannot protect against it.
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        $cart = $gateway->addToCart('fx-021', 10);

        self::assertSame(8, $cart->lineItems[0]?->quantity);
        self::assertSame(CartNoticeReason::PurchaseSteps, $cart->notices[0]?->reason);
        self::assertSame('fx-021', $cart->notices[0]?->variantId);
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Core/Commerce/FixtureCommerceGatewayTest.php
```

Expected: FAIL — the fixture stores 10 and emits no notices.

- [ ] **Step 3: Constrain the fixture product**

In `tests/Fixtures/catalog.json`, on the `fx-021` object, add the two fields beside `stock`:

```json
    "minPurchase": 4,
    "purchaseSteps": 4,
```

- [ ] **Step 4: Teach the fixture index and gateway the constraints**

In `src/Core/Commerce/Fixture/FixtureIndex.php`, add `minPurchase` and `purchaseSteps` as optional integer keys to the `FixtureProduct` and `FixtureVariant` `@phpstan-type` blocks, default both to 1 when absent, and carry them on the sellable unit alongside `price` and `stock`.

In `src/Core/Commerce/FixtureCommerceGateway.php`, apply them in `addToCart()` before the line is stored, mirroring `ProductCartProcessor::fixQuantity()`:

```php
    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        $unit = $this->index->unit($variantId);
        if ($unit === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown variant id "%s".', $variantId));
        }

        // Shopware does not refuse a quantity that breaks a product's purchase rules — it changes
        // it and records why (`ProductCartProcessor::validateStock()`). A fixture that stored the
        // requested quantity could not reproduce that, which is exactly why the tool's
        // misreporting survived every fixture test this project has.
        $corrected = self::fixQuantity($unit->minPurchase, $quantity, $unit->purchaseSteps);
        $notices = $corrected === $quantity
            ? []
            : [new CartNotice($variantId, $quantity < $unit->minPurchase
                ? CartNoticeReason::MinimumQuantity
                : CartNoticeReason::PurchaseSteps)];

        $existing = $this->cartLines[$variantId] ?? null;
        $newQuantity = ($existing === null ? 0 : $existing->quantity) + $corrected;

        $this->cartLines[$variantId] = new CartLine(
            lineId: $variantId,
            variantId: $variantId,
            name: $unit->name,
            quantity: $newQuantity,
            unitPrice: $unit->price,
            lineTotal: $newQuantity * $unit->price,
        );

        return $this->cart($notices);
    }

    /**
     * Shopware's own rounding, from `ProductCartProcessor::fixQuantity()`: raise to the minimum,
     * then step down to the nearest legal multiple above it.
     */
    private static function fixQuantity(int $min, int $quantity, int $steps): int
    {
        return (int) ($min + floor(($quantity - $min) / $steps) * $steps);
    }
```

`cart()` gains an optional `$notices` parameter defaulting to `[]` so the read path is unchanged.

- [ ] **Step 5: Run the deterministic tests**

```bash
composer test
```

Expected: PASS. If another test or journey depended on `fx-021` storing what it was given, that is the grep from the task preamble having been skipped — pick a different product rather than relaxing the assertion.

- [ ] **Step 6: Write the journey**

Create `tests/Journeys/cart_quantity_corrected.php`:

```php
<?php

declare(strict_types=1);

return [
    'id' => 'cart_quantity_corrected',
    'category' => 'action',
    'runs' => 3,
    'archetypes' => [
        'expert' => null,
        'beginner' => null,
    ],
    'config' => [],
    // fx-021 is sold in fours. Ten is not a legal quantity, Shopware stores eight, and the one
    // thing the reply must not do is confirm the ten the shopper asked for.
    'turns' => [
        'add 10 of the Shimano disc brake pad set to my cart',
    ],
    'assertions' => [
        'no_invented_product' => [],
        'cart_contains' => ['variantId' => 'fx-021'],
    ],
];
```

- [ ] **Step 7: Run the eval and read the reply**

```bash
vendor/bin/phpunit --group eval --filter cart_quantity_corrected
```

Expected: PASS. Then read the transcript the runner prints and confirm by eye that the reply states eight, or says the quantity was adjusted, and does not assert that ten were added. The `cart_contains` assertion cannot check prose; this step is a human read, and it is the point of the journey.

To see the before/after for yourself, run the same command with Task 5 reverted:

```bash
git stash push src/Core/Tool/AddToCartTool.php
vendor/bin/phpunit --group eval --filter cart_quantity_corrected
git stash pop
```

The reply will confirm ten while the cart holds eight.

- [ ] **Step 8: Commit**

```bash
git add src/Core/Commerce/Fixture/FixtureIndex.php src/Core/Commerce/FixtureCommerceGateway.php \
        tests/Fixtures/catalog.json tests/Journeys/cart_quantity_corrected.php \
        tests/Core/Commerce/FixtureCommerceGatewayTest.php
git commit -m "test(eval): give the fixture Shopware's quantity correction"
```

---

### Task 7: The live shop agrees with the assistant

The proof that matters. Everything above runs against fixtures or hand-built entities; only a real Shopware populates `calculatedPrices`, and only this task shows the defect actually gone.

**Background the implementer needs.** `tests/e2e/pricing.spec.js` already exists and **already fails** — it was written before the implementation, run against the local demo shop on 2026-08-28, and its docblock records the six-product baseline. It costs no model call and takes seconds. The demo shop at `~/Workspace/shopping-assistant-test` mounts this repository at `/var/www/html/plugin-src`, so local edits are live after a cache clear.

One shop mutation was made to create the baseline, and it must stay for these tests to mean anything: product `01a01b4f981c70eabd51f14e553875ec` (Aerodynamic Concrete PortGear) was given `min_purchase = 4, purchase_steps = 4`. Revert with:

```sql
UPDATE product SET min_purchase = 1, purchase_steps = 1
WHERE id = UNHEX('01a01b4f981c70eabd51f14e553875ec');
```

**Files:**
- Verify: `tests/e2e/pricing.spec.js` (no edit expected)
- Modify: `tests/e2e/README.md` (record the seeded product and the new spec)

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Confirm the spec still fails before you refresh the shop**

```bash
SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/pricing.spec.js
```

Expected: 2 failed, listing six card/page divergences. If it passes here, the shop already picked up the fix and this step proves nothing — note that and continue.

- [ ] **Step 2: Refresh the shop with the built plugin**

```bash
docker exec shopping-assistant-test-web-1 sh -lc 'php bin/console cache:clear && php bin/console theme:compile'
```

Expected: both succeed. The plugin source is symlinked, so no copy step is needed; the storefront bundle from Task 3 Step 5 is what `theme:compile` picks up.

- [ ] **Step 3: Run the spec again**

```bash
SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/pricing.spec.js
```

Expected: **2 passed.** Every sampled card now matches its product page, and the seeded product's card reports `priceQuantity: 4`.

If a single product still diverges, do not adjust the tolerance. Read that product's `product_price` rows and work out which rule Shopware matched — a genuine mismatch here means the selection logic disagrees with `filterRulePrices`, which is a real finding and belongs back in the spec.

- [ ] **Step 4: Prove the cart half in the browser**

This one fires a real model turn, so it is run by hand rather than added to a suite:

```bash
SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/widget.spec.js -g "cart"
```

Then, in a browser at `http://127.0.0.1:8000`, open the assistant and ask:

> add 10 of the Aerodynamic Concrete PortGear to my cart

Expected: the reply states **8**, not 10, and gives the fixed-steps reason. Open `/checkout/cart` and confirm the line holds 8. Record both in the commit message.

- [ ] **Step 5: Record the shop state the suite assumes**

Append to `tests/e2e/README.md`, under a new `## Shop data these checks assume` heading: the seeded product id, the two column values, the revert SQL from this task's preamble, and the SQL that finds tier-priced products (already in `pricing.spec.js`'s docblock — cross-reference rather than duplicating it).

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/README.md
git commit -m "docs(e2e): record the shop data the pricing checks assume"
```

---

### Task 8: Full gate and spec reconciliation

Both corrections are shipped. This closes the loop with the documents that claim what the plugin does.

**Files:**
- Modify: `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md` (section 16 Sequencing — mark items 1 and 2 done)
- Verify only: `README.md`, `HANDOFF.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Run the complete quality gate**

```bash
composer test && composer quality && npm run test:js && \
  SHOP_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/pricing.spec.js
```

Expected: PASS throughout. `composer quality` runs format check, lint, static analysis, file size, duplication, dependency and audit checks. If `mago` reports formatting drift, run `composer format` and re-run — do not hand-edit around the formatter.

- [ ] **Step 2: Check whether the docs contradict the new behavior**

```bash
grep -rn "getCalculatedPrice\|Added %d\|calculatedPrice" README.md HANDOFF.md ARCHITECTURE.md
```

Read each hit. Any sentence claiming the card price comes from `getCalculatedPrice()`, or that the cart reports the requested quantity, is now false and must be corrected in place. If there are no hits beyond the `ARCHITECTURE.md` DTO blocks already updated in Tasks 2 and 4, record that and move on.

- [ ] **Step 3: Mark the spec's sequencing items complete**

In `docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md`, in the `### Sequencing` block of section 16, prefix items 1 and 2 with `**Done 2026-08-28.**` so the B2B plans that follow do not re-plan them.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-08-27-b2b-commercial-context-design.md README.md HANDOFF.md ARCHITECTURE.md
git commit -m "docs: record the price and cart corrections as shipped"
```

---

## Out of scope for this plan, on purpose

Named here so an implementer does not helpfully add them:

- The `quantity` argument on the product tools, quantity-aware card references, and the `{productId, quantity}` transcript format. Spec sections 7.2 and 7.4, next plan.
- `minPurchase`, `purchaseSteps`, `calculatedMaxPurchase` and pack unit as card fields, and pre-validating a quantity before the cart call. Spec section 7.3's first half, next plan.
- Anything touching `ShoppingContext`, conversation scope, the browser token map or Commercial. Spec sections 5, 9, 8.
- Rendering a tier table. Spec section 7.2 explicitly defers it.
