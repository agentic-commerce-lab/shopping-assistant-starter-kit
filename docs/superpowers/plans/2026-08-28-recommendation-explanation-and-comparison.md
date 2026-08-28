# Recommendation Explanation & Product Comparison Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the assistant explain why a product was retrieved (Journey 2) and compare products by material/attributes (Journey 3), by widening what the model sees and extending the existing grounding-audit pattern to cover the new claim surface — without weakening D3/D4.

**Architecture:** A shared Phase 1 widens `ProductCard::$properties` into the model-facing tool shape and adds a closed-vocabulary attribute audit mirroring the existing price/availability audits, plus a `Warnings` value object so `AssistantTurn` can carry a third (and future) warning without breaching mago's parameter-count gate. Phase 2a adds a deterministic, code-based match-reason signal to `search_products`. Phase 2b adds a bounded `compare_products` tool and compact spec-chips on the existing card UI. No new comparison table, no personalization, no free-text `description` exposure.

**Tech Stack:** PHP 8.2 (Symfony AI Agent/Toolbox, Shopware 6.7 plugin), vanilla JS storefront widget (`src/Resources/app/storefront/src/assistant`), PHPUnit 11, Node's built-in test runner for JS, mago (lint/format/analyze).

**Spec:** `docs/superpowers/specs/2026-08-28-recommendation-explanation-and-comparison-design.md`

## Global Constraints

- **D3/D4 (from the spec/codebase):** the model never states a price, stock figure, delivery time or URL itself; every shopper-facing figure is rendered server-side from a `ProductCard` the turn actually retrieved.
- **Closed vocabulary only.** The model's view widens to `properties` (structured, drawn from the shop's own facet vocabulary) — never `description` (free text). Both phases stay inside this boundary; do not add `description` to any tool-facing or audit-facing shape.
- **No personalization.** Match reasons are about *why a product was retrieved for this query* — matched term, stock standing — never shopper history or preference weighting. There is no such data source in this codebase; do not add one.
- **No "quality" field.** Nothing invents a subjective quality judgement. Everything the model or the audit reasons about is a literal value from `ProductCard::$properties`.
- **No comparison table UI.** The assistant panel defaults to 420px (`$swag-assistant-panel-width`, `_tokens.scss`) with 176px cards in a horizontally-scrolling row. Comparison is prose + compact per-card spec chips, reusing the existing card, never a new grid/table component.
- **mago's `excessive-parameter-list` is `error`-level at threshold 5** (`mago.toml`) — a CI-failing gate, not a style nit. Any new constructor must stay at or under 5 parameters; `AssistantTurn` is handled explicitly in Task 1.
- **New tools/fields are capability-gated**, never prompt-instructed: a disabled capability is simply never constructed (mirrors `enableAddToCart`/`enableEscalation`), so it never appears in what the model sees.
- **Reason codes, not new claim shapes.** Match reasons reuse the `reasonCode` convention `BlocklistFilter` already established (`blocked_product`, `blocked_category`), not a new ad hoc structure.
- **Verification bar:** `composer test` (984 tests, 18854 assertions, baseline captured 2026-08-28) and `composer quality` must stay green throughout. `composer test:eval` makes real LLM calls against `ASSISTANT_LLM_BASE_URL` — run it deliberately at named checkpoints (Tasks 13, 17, 22), never incidentally. **Real baseline captured 2026-08-28, default catalogue: 29 journeys, 18 ran, 18 passed, 11 skipped (the `scale_*`/`fashion_*` journeys, which need `ASSISTANT_EVAL_CATALOG=large`/`=fashion`), 0 failures.** Tasks 13 and 22 additionally run the large and fashion catalogues — not just the default one — since this plan's own Task 5 finding (`PropertyClaimExtractor` must exclude the `categoryPath` facet, and must treat `options` as backing too, both confirmed by measuring the real `large`/`fashion` facet sets: 62 fields/623 values on `large`, `properties.Material` genuinely present on `fashion`) is exactly the kind of gap that stays invisible on the twelve-product default catalogue alone.

---

## File Structure

**New PHP files:**
- `src/Core/Agent/Warnings.php` — value object bundling the turn's grounding-audit findings
- `src/Core/Tool/BoundedProperties.php` — caps property groups/values per product (model- and storefront-facing)
- `src/Core/Grounding/PropertyClaimExtractor.php` — finds known facet values verbatim in prose
- `src/Core/Grounding/BackedPropertyValues.php` — the set of property values the rendered cards actually back
- `src/Eval/Assertion/NoUnbackedPropertyClaimInProse.php` — new journey assertion
- `src/Core/Tool/MatchReasons.php` — deterministic per-product reason codes for `search_products`
- `src/Core/Tool/CompareProductsTool.php` — the `compare_products` tool
- `src/Core/Tool/Factory/CompareProductsToolFactory.php`

**New test files:**
- `tests/Core/Agent/WarningsTest.php`
- `tests/Eval/TurnAggregateWarningsTest.php`
- `tests/Core/Tool/BoundedPropertiesTest.php`
- `tests/Core/Tool/ToolProductSummaryTest.php`
- `tests/Core/Grounding/PropertyClaimExtractorTest.php`
- `tests/Core/Grounding/ProseAuditPropertyTest.php`
- `tests/Core/Grounding/FactRendererUnbackedPropertiesTest.php`
- `tests/Core/Tool/MatchReasonsTest.php`
- `tests/Core/Tool/CompareProductsToolTest.php`
- `tests/js/spec-chips.test.js`
- `tests/Journeys/property_grounded_claim.php`
- `tests/Journeys/property_claim_unbacked.php`
- `tests/Journeys/why_matched_term.php`
- `tests/Journeys/compare_two_products.php`

**Modified files:**
- `src/Core/Agent/AssistantTurn.php` — 4-param constructor (`prose`, `cards`, `outcome`, `warnings`)
- `src/Core/Agent/AssistantRunner.php` — builds `Warnings`, calls the new property audit
- `src/Eval/TurnAggregate.php` — merges all three warning lists (fixes a pre-existing gap: availability claims were never merged across multi-turn journeys)
- `tests/Eval/TurnAggregateMergeTest.php`, `tests/Eval/Assertion/NoUnbackedPriceInProseTest.php` — update the two call sites that name `unbackedPrices:`/`unbackedAvailabilityClaims:` directly
- `src/Core/Tool/ToolProductSummary.php` — adds `properties`, later `reasons`
- `src/Core/Tool/SearchProductsTool.php` — computes and attaches match reasons when enabled
- `src/Core/Grounding/ProseAudit.php` — adds `unbackedProperties()`
- `src/Core/Grounding/FactRenderer.php` — adds `unbackedPropertiesInProse()`/`unbackedProperties()`
- `src/Core/Agent/GroundingOutputProcessor.php` — takes a `FacetSet`, calls the new audit
- `src/Core/Agent/AssistantAgentFactory.php` — captures `$facets` once, passes to the processor; registers `CompareProductsToolFactory` in `withCoreToolsOnly()`
- `src/Core/Prompt/SystemPrompt.php` — two short rule additions
- `src/Core/Policy/AssistantConfig.php` — `enableMatchReasons`, `enableCompareProducts` flags
- `src/Eval/JourneyConfig.php` — allow-lists the two new config keys
- `src/Eval/Assertion/AssertionRegistry.php` — registers the new assertion
- `src/Controller/AssistantController.php::warnings()` — reads from `$turn->warnings`, adds the third key
- `src/Controller/CardPayload.php` — adds bounded `properties`
- `src/Resources/app/storefront/src/assistant/render.js` — `buildWarning()` gets a third check; new `formatSpecChips()`
- `src/Resources/app/storefront/src/assistant/card.js` — renders the spec-chips line
- `src/Resources/app/storefront/src/scss/components/_card.scss` — spec-chips styling
- `src/Resources/views/storefront/component/assistant/panel.html.twig` — `warningProperty` translation key
- `src/Resources/snippet/swag-assistant.en.json`, `.de.json` — new strings
- `src/Resources/config/services.xml` — registers `CompareProductsToolFactory`

---

## PHASE 1 — Shared grounding foundation

### Task 1: `Warnings` value object, `AssistantTurn` refactor

**Files:**
- Create: `src/Core/Agent/Warnings.php`
- Modify: `src/Core/Agent/AssistantTurn.php`
- Test: `tests/Core/Agent/WarningsTest.php`

**Interfaces:**
- Produces: `Warnings::__construct(array $unbackedPrices = [], array $unbackedAvailabilityClaims = [], array $unbackedPropertyClaims = [])`, all `list<string>`.
- Produces: `AssistantTurn::__construct(string $prose, array $cards, string $outcome, Warnings $warnings = new Warnings())` — down from 5 positional params to 4, so mago's threshold-5 `excessive-parameter-list` rule never fires again as more claim types are added.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\Warnings;

final class WarningsTest extends TestCase
{
    public function testDefaultsToNoWarnings(): void
    {
        $warnings = new Warnings();

        self::assertSame([], $warnings->unbackedPrices);
        self::assertSame([], $warnings->unbackedAvailabilityClaims);
        self::assertSame([], $warnings->unbackedPropertyClaims);
    }

    public function testCarriesEachClaimTypeIndependently(): void
    {
        $warnings = new Warnings(
            unbackedPrices: ['12.90'],
            unbackedAvailabilityClaims: ['is available'],
            unbackedPropertyClaims: ['Merino'],
        );

        self::assertSame(['12.90'], $warnings->unbackedPrices);
        self::assertSame(['is available'], $warnings->unbackedAvailabilityClaims);
        self::assertSame(['Merino'], $warnings->unbackedPropertyClaims);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Agent/WarningsTest.php`
Expected: FAIL — class `Warnings` not found.

- [ ] **Step 3: Create `Warnings` and refactor `AssistantTurn`**

`src/Core/Agent/Warnings.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

/**
 * The ways a turn's prose can contradict what the server actually rendered, bundled into one value
 * object because AssistantTurn's own constructor sits at mago's excessive-parameter-list threshold —
 * a fourth flat list would breach it. A new claim type is added here, never as a new AssistantTurn
 * parameter.
 */
final readonly class Warnings
{
    /**
     * @param list<string> $unbackedPrices
     * @param list<string> $unbackedAvailabilityClaims
     * @param list<string> $unbackedPropertyClaims
     */
    public function __construct(
        public array $unbackedPrices = [],
        public array $unbackedAvailabilityClaims = [],
        public array $unbackedPropertyClaims = [],
    ) {}
}
```

Replace `src/Core/Agent/AssistantTurn.php`'s contents with:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The result of one {@see AssistantRunner::run()} call: the shopper-facing prose, the cards
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} actually rendered (never anything the
 * model said, unsubstituted), the machine-readable outcome for logging/analytics, and every way the
 * prose can contradict the cards — see {@see Warnings}.
 */
final readonly class AssistantTurn
{
    /**
     * @param list<ProductCard> $cards
     */
    public function __construct(
        public string $prose,
        public array $cards,
        public string $outcome,
        public Warnings $warnings = new Warnings(),
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Agent/WarningsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Agent/Warnings.php src/Core/Agent/AssistantTurn.php tests/Core/Agent/WarningsTest.php
git commit -m "feat: bundle turn warnings into a value object so AssistantTurn stays under mago's parameter cap"
```

---

### Task 2: Update every `AssistantTurn` construction site for the new shape

**Files:**
- Modify: `src/Core/Agent/AssistantRunner.php:107`
- Modify: `src/Eval/TurnAggregate.php`
- Modify: `tests/Eval/TurnAggregateMergeTest.php`
- Modify: `tests/Eval/Assertion/NoUnbackedPriceInProseTest.php`
- Test: `tests/Eval/TurnAggregateWarningsTest.php` (new — covers the availability-merge fix)

**Interfaces:**
- Consumes: `Warnings` from Task 1.
- Produces: `TurnAggregate::of(list<AssistantTurn> $turns): AssistantTurn` unchanged in signature, but its returned turn's `warnings` now merges `unbackedPrices`, `unbackedAvailabilityClaims`, and `unbackedPropertyClaims` across every turn (previously only `unbackedPrices` was merged — availability claims from an earlier turn in a multi-turn journey were silently dropped).

- [ ] **Step 1: Write the failing test** — `tests/Eval/TurnAggregateWarningsTest.php`

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\Warnings;
use Swag\AssistantStarterKit\Eval\TurnAggregate;

final class TurnAggregateWarningsTest extends TestCase
{
    public function testMergesAvailabilityAndPropertyClaimsAcrossTurns(): void
    {
        $turnOne = new AssistantTurn(
            prose: 'Turn one.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(unbackedAvailabilityClaims: ['is available']),
        );
        $turnTwo = new AssistantTurn(
            prose: 'Turn two.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(unbackedPropertyClaims: ['Merino']),
        );

        $merged = TurnAggregate::of([$turnOne, $turnTwo]);

        self::assertSame(['is available'], $merged->warnings->unbackedAvailabilityClaims);
        self::assertSame(['Merino'], $merged->warnings->unbackedPropertyClaims);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Eval/TurnAggregateWarningsTest.php`
Expected: FAIL — `AssistantTurn::__construct()` does not accept `unbackedAvailabilityClaims`/`unbackedPropertyClaims` as named args (they're `warnings:` now) or `->warnings->unbackedAvailabilityClaims` does not merge.

- [ ] **Step 3: Fix the three call sites**

`src/Core/Agent/AssistantRunner.php` around line 95-107 — replace:

```php
        $cards = $this->bundle->renderer->renderedCards();
        $unbackedPrices = $this->bundle->renderer->unbackedPrices();
        $unbackedAvailability = $this->bundle->renderer->unbackedAvailability();

        $outcome = $this->outcomeResolver->outcome($this->bundle->trace, $cards);

        $this->recordTurnEnd($outcome, $cards);

        $prose = $result instanceof TextResult ? $result->getContent() : '';

        $this->auditPeriods($prose);

        return new AssistantTurn($prose, $cards, $outcome, $unbackedPrices, $unbackedAvailability);
```

with:

```php
        $cards = $this->bundle->renderer->renderedCards();

        $outcome = $this->outcomeResolver->outcome($this->bundle->trace, $cards);

        $this->recordTurnEnd($outcome, $cards);

        $prose = $result instanceof TextResult ? $result->getContent() : '';

        $this->auditPeriods($prose);

        $warnings = new Warnings(
            unbackedPrices: $this->bundle->renderer->unbackedPrices(),
            unbackedAvailabilityClaims: $this->bundle->renderer->unbackedAvailability(),
            unbackedPropertyClaims: $this->bundle->renderer->unbackedProperties(),
        );

        return new AssistantTurn($prose, $cards, $outcome, $warnings);
```

Add `use Swag\AssistantStarterKit\Core\Agent\Warnings;` (same namespace — no import needed, `Warnings` lives in `Core\Agent` alongside `AssistantRunner`). `unbackedProperties()` does not exist yet on `FactRenderer` — this line is finished by Task 8; leave it as a call the class doesn't yet satisfy, since Task 8 lands before this task is verified end-to-end (see the task ordering note below the checklist).

`src/Eval/TurnAggregate.php` — replace the merge body:

```php
        /** @var array<string, \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard> $cardsById */
        $cardsById = [];
        $proseParts = [];
        $unbackedPrices = [];
        $unbackedAvailability = [];
        $unbackedProperties = [];
        $lastTurn = null;

        foreach ($turns as $turn) {
            $lastTurn = $turn;
            $proseParts[] = $turn->prose;

            foreach ($turn->cards as $card) {
                $cardsById[$card->id] = $card;
            }

            foreach ($turn->warnings->unbackedPrices as $price) {
                $unbackedPrices[] = $price;
            }

            foreach ($turn->warnings->unbackedAvailabilityClaims as $claim) {
                $unbackedAvailability[] = $claim;
            }

            foreach ($turn->warnings->unbackedPropertyClaims as $claim) {
                $unbackedProperties[] = $claim;
            }
        }

        if (null === $lastTurn) {
            // Unreachable: the empty-list check above already threw.
            throw new \LogicException('Cannot aggregate an empty list of turns.');
        }

        return new AssistantTurn(
            prose: implode("\n", $proseParts),
            cards: array_values($cardsById),
            outcome: $lastTurn->outcome,
            warnings: new Warnings(
                unbackedPrices: array_values(array_unique($unbackedPrices)),
                unbackedAvailabilityClaims: array_values(array_unique($unbackedAvailability)),
                unbackedPropertyClaims: array_values(array_unique($unbackedProperties)),
            ),
        );
```

Add `use Swag\AssistantStarterKit\Core\Agent\Warnings;` to this file's imports.

`tests/Eval/TurnAggregateMergeTest.php` — update the two `new AssistantTurn(..., unbackedPrices: [...])` constructions (around lines 28-38) to `warnings: new Warnings(unbackedPrices: [...])`, and add `use Swag\AssistantStarterKit\Core\Agent\Warnings;`.

`tests/Eval/Assertion/NoUnbackedPriceInProseTest.php` — update its two `new AssistantTurn(..., unbackedPrices: [...])` constructions the same way.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Eval/TurnAggregateWarningsTest.php tests/Eval/TurnAggregateMergeTest.php tests/Eval/Assertion/NoUnbackedPriceInProseTest.php`
Expected: the new test PASSes; the two updated tests PASS unchanged in behavior. `AssistantRunner.php`'s reference to `unbackedProperties()` will not type-check yet — do not run `composer typecheck`/`composer test` for the whole suite until Task 8 lands; run only the four targeted test files above at this step.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Agent/AssistantRunner.php src/Eval/TurnAggregate.php tests/Eval/TurnAggregateMergeTest.php tests/Eval/Assertion/NoUnbackedPriceInProseTest.php tests/Eval/TurnAggregateWarningsTest.php
git commit -m "fix: merge availability and property claims across multi-turn journeys, not just prices"
```

---

### Task 3: `BoundedProperties` — the shared bound for exposing product attributes

**Files:**
- Create: `src/Core/Tool/BoundedProperties.php`
- Test: `tests/Core/Tool/BoundedPropertiesTest.php`

**Interfaces:**
- Produces: `BoundedProperties::of(array $properties): array` — `array<string, list<string>> → array<string, list<string>>`, capped at 6 groups and 4 values per group. Used by Task 4 (model-facing) and Task 19 (storefront-facing) so both share one bound.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;

final class BoundedPropertiesTest extends TestCase
{
    public function testPassesThroughASmallPropertySet(): void
    {
        $properties = ['Material' => ['Merino'], 'Fit' => ['Regular']];

        self::assertSame($properties, BoundedProperties::of($properties));
    }

    public function testCapsValuesPerGroupAtFour(): void
    {
        $properties = ['Colour' => ['Red', 'Blue', 'Green', 'Black', 'White', 'Yellow']];

        self::assertSame(['Colour' => ['Red', 'Blue', 'Green', 'Black']], BoundedProperties::of($properties));
    }

    public function testCapsGroupsAtSix(): void
    {
        $properties = [];
        for ($i = 1; $i <= 8; ++$i) {
            $properties['Group' . $i] = ['Value'];
        }

        self::assertCount(6, BoundedProperties::of($properties));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/BoundedPropertiesTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * The one bound both the model-facing tool shape ({@see ToolProductSummary}) and the storefront-facing
 * card payload ({@see \Swag\AssistantStarterKit\Controller\CardPayload}) apply to a single product's
 * `properties`, so a product with a sprawling attribute list cannot inflate either surface unevenly.
 *
 * Deliberately simpler than {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabularyBudget}: that
 * class bounds a whole catalogue's vocabulary block by character budget, because a facet set spans
 * every product at once. This bounds one product's own properties, where a flat group/value cap is
 * already enough — there is no rendered-text budget to fit.
 */
final class BoundedProperties
{
    private const MAX_GROUPS = 6;

    private const MAX_VALUES_PER_GROUP = 4;

    private function __construct() {}

    /**
     * @param array<string, list<string>> $properties
     *
     * @return array<string, list<string>>
     */
    public static function of(array $properties): array
    {
        $bounded = [];

        foreach (\array_slice($properties, 0, self::MAX_GROUPS, true) as $group => $values) {
            $bounded[$group] = \array_slice($values, 0, self::MAX_VALUES_PER_GROUP);
        }

        return $bounded;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Tool/BoundedPropertiesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Tool/BoundedProperties.php tests/Core/Tool/BoundedPropertiesTest.php
git commit -m "feat: add BoundedProperties, the shared per-product attribute cap"
```

---

### Task 4: Widen `ToolProductSummary` with bounded `properties`

**Files:**
- Modify: `src/Core/Tool/ToolProductSummary.php`
- Test: `tests/Core/Tool/ToolProductSummaryTest.php` (new)

**Interfaces:**
- Consumes: `BoundedProperties::of()` from Task 3.
- Produces: `ToolProductSummary::of(list<ProductCard> $cards): list<array{id: string, name: string, options: array<string,string>, properties: array<string, list<string>>}>` — both `search_products` and `get_product` return this shape unchanged except for the new key.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

final class ToolProductSummaryTest extends TestCase
{
    public function testIncludesBoundedProperties(): void
    {
        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: 'A jersey.',
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            options: ['Colour' => 'Blue'],
            properties: ['Material' => ['Merino', 'Nylon']],
        );

        $result = ToolProductSummary::of([$card]);

        self::assertSame(['Material' => ['Merino', 'Nylon']], $result[0]['properties']);
    }

    public function testAnEmptyPropertyListStaysEmpty(): void
    {
        $card = new ProductCard(
            id: 'fx-002',
            parentId: null,
            name: 'Plain Bottle',
            description: null,
            price: 9.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-002',
            imageUrl: null,
        );

        $result = ToolProductSummary::of([$card]);

        self::assertSame([], $result[0]['properties']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/ToolProductSummaryTest.php`
Expected: FAIL — no `properties` key in the returned array.

- [ ] **Step 3: Implement**

In `src/Core/Tool/ToolProductSummary.php`, add the import `use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;` is unnecessary (same namespace); update the docblock's `@return` shape and the `of()` body:

```php
    /**
     * @param list<ProductCard> $cards
     *
     * @return list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>}>
     */
    public static function of(array $cards): array
    {
        return array_map(static fn(ProductCard $card): array => [
            'id' => $card->id,
            'name' => $card->name,
            'options' => $card->options,
            // Structured, closed-vocabulary attributes only (material, and similar) — never
            // `description`, which is free text with no closed vocabulary to audit against. See
            // BoundedProperties for the per-product cap, and ProseAudit::unbackedProperties() for the
            // audit this now requires: a value stated in prose must be backed by a rendered card.
            'properties' => BoundedProperties::of($card->properties),
        ], $cards);
    }
```

Also update the class docblock's "**Never widen this.**" paragraph to reflect that `properties` is the one deliberate, audited exception — replace:

> **Never widen this.** A price or stock number here would let the model quote a figure it did not have to earn, which is the one thing this whole pipeline exists to prevent.

with:

> **Never widen this with a figure or free text.** A price or stock number here would let the model quote a figure it did not have to earn — the one thing this whole pipeline exists to prevent. `properties` is the one deliberate exception: it is closed-vocabulary (drawn from the shop's own facet values) and audited by {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unbackedProperties()}, the same way price is audited. `description` must never be added here — it is free text with no closed vocabulary to audit against.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Tool/ToolProductSummaryTest.php tests/Core/Tool/SearchProductsToolTest.php tests/Core/Tool/GetProductToolTest.php`
Expected: PASS (the two existing tool tests still pass — they check `id`/`name`/`options`, unaffected by an added key).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Tool/ToolProductSummary.php tests/Core/Tool/ToolProductSummaryTest.php
git commit -m "feat: expose bounded product properties to the model via search_products and get_product"
```

---

### Task 5: `PropertyClaimExtractor`

**Files:**
- Create: `src/Core/Grounding/PropertyClaimExtractor.php`
- Test: `tests/Core/Grounding/PropertyClaimExtractorTest.php`

**Interfaces:**
- Consumes: `FacetSet`/`Facet` (`src/Core/Commerce/Dto/{FacetSet,Facet}.php`) — `Facet::$values` is `list<string>`.
- Produces: `PropertyClaimExtractor::extract(string $prose, FacetSet $facets): list<string>` — known facet values found verbatim (case-insensitive, whole-token) in the prose, deduplicated, in the shop's own casing.

**A real, verified constraint on this class, found by measuring the actual `large`/`fashion` eval catalogues before writing this task:** `FacetSet` carries more than product attributes. `FixtureFacetBuilder::build()` (`src/Core/Commerce/Fixture/FixtureFacetBuilder.php`) and `DalFacetReader` (`src/Core/Commerce/Dal/DalFacetReader.php`, production) both also emit a `categoryPath` Terms facet with real string values — measured at 399 values on the fashion catalogue — and a `price` Range facet. Neither is a product property, and `categoryPath` in particular has genuine string values that could coincidentally appear in prose. Both builders namespace true property-group facets under a shared `properties.` prefix (`FixtureFacetBuilder`: `sprintf('properties.%s', $group)`; `DalFacetReader::GROUP_PREFIX = 'properties.'`) — so this class must only scan facets whose field starts with that prefix, or every category-name mention becomes a false "unbacked property claim."

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Grounding\PropertyClaimExtractor;

final class PropertyClaimExtractorTest extends TestCase
{
    private function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon']),
            new Facet('properties.Colour', FacetType::Terms, ['Blue', 'Black']),
            // Not a property — must never be scanned. Real value taken from the fashion catalogue
            // measurement above, so this test fails honestly if the exclusion is ever dropped.
            new Facet('categoryPath', FacetType::Terms, ['Dresses']),
        ]);
    }

    public function testFindsAKnownValueCaseInsensitively(): void
    {
        $found = (new PropertyClaimExtractor())->extract('It is made of merino wool.', $this->facets());

        self::assertSame(['Merino'], $found);
    }

    public function testFindsNothingWhenNoKnownValueAppears(): void
    {
        $found = (new PropertyClaimExtractor())->extract('It is a great jersey.', $this->facets());

        self::assertSame([], $found);
    }

    public function testDoesNotMatchAValueInsideALongerWord(): void
    {
        // "Nylon" must not match inside "Nylontex", a hypothetical brand word.
        $found = (new PropertyClaimExtractor())->extract('Made by Nylontex.', $this->facets());

        self::assertSame([], $found);
    }

    public function testDeduplicatesRepeatedMentions(): void
    {
        $found = (new PropertyClaimExtractor())->extract('Merino, Merino everywhere.', $this->facets());

        self::assertSame(['Merino'], $found);
    }

    public function testNeverScansACategoryPathFacet(): void
    {
        // "Dresses" is a real categoryPath value in this fixture set, and appears in the prose —
        // it must not be extracted as a property claim, since it is not one.
        $found = (new PropertyClaimExtractor())->extract('We have lovely Dresses in stock.', $this->facets());

        self::assertSame([], $found);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Grounding/PropertyClaimExtractorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;

/**
 * Finds known catalogue attribute values — the shop's own closed vocabulary, the same
 * {@see FacetSet} {@see \Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary} already renders into
 * the prompt — that appear verbatim in a shopper-facing reply.
 *
 * Unlike {@see CurrencyFigureExtractor}, which recognises a price by its own shape (a currency token,
 * or two decimals), an attribute value has no structural marker: "Blue" is indistinguishable from an
 * ordinary word by shape alone. What makes this tractable is the closed universe instead — only a
 * literal, exact value from this turn's own facets is ever extracted, never an inferred or synonymous
 * one. A property value that also happens to be an ordinary English word can still produce a false
 * positive; accepted for v1 per the design spec, revisit if it proves noisy.
 *
 * **Only `properties.*` facets are scanned.** `FacetSet` also carries `categoryPath` (a real Terms
 * facet, not a product attribute — measured at 399 values on the fashion eval catalogue) and `price`
 * (a Range facet already excluded by requiring `properties.` prefix, belt-and-braces with the type
 * check below). Both `FixtureFacetBuilder` and the production `DalFacetReader` namespace true
 * property-group facets under this same `properties.` prefix — see either class's own docblock —
 * so filtering on it is the shop's own convention, not one invented here.
 */
final class PropertyClaimExtractor
{
    private const PROPERTY_FIELD_PREFIX = 'properties.';

    /**
     * @return list<string> known facet values found in the prose, in the shop's own casing, deduplicated
     */
    public function extract(string $prose, FacetSet $facets): array
    {
        $normalisedProse = ' ' . mb_strtolower($prose) . ' ';
        $found = [];

        foreach ($facets->facets as $facet) {
            if (FacetType::Terms !== $facet->type || !str_starts_with($facet->field, self::PROPERTY_FIELD_PREFIX)) {
                continue;
            }

            foreach ($facet->values as $value) {
                if ($value === '' || \in_array($value, $found, true)) {
                    continue;
                }

                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($value), '/') . '(?![\p{L}\p{N}])/u';

                if (preg_match($pattern, $normalisedProse) === 1) {
                    $found[] = $value;
                }
            }
        }

        return $found;
    }
}
```

Add `use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;` to this file's imports.

**This also changes Task 4 and Task 15's shape slightly**, worth noting here since it is easy to miss: `ToolProductSummary`'s exposed `properties` key (Task 4) is unaffected — it reads `ProductCard::$properties` directly, not facets, so it already only ever carries true properties. Only the *audit* needed the prefix filter, because only the audit reads the wider `FacetSet`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Grounding/PropertyClaimExtractorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Grounding/PropertyClaimExtractor.php tests/Core/Grounding/PropertyClaimExtractorTest.php
git commit -m "feat: add PropertyClaimExtractor, closed-vocabulary attribute detection in prose"
```

---

### Task 6: `BackedPropertyValues`

**Files:**
- Create: `src/Core/Grounding/BackedPropertyValues.php`
- Test: covered inline by Task 7's `ProseAuditPropertyTest.php` (this class has no independent public contract worth testing separately from the audit that consumes it — mirrors how `BackedFigures` is exercised only through `ProseAuditTest`/`FactRendererUnbackedPricesTest`, not its own dedicated test file).

**Interfaces:**
- Produces: `BackedPropertyValues::of(list<ProductCard> $rendered): array<string, true>` — every value any rendered card's `properties` **or** `options` carries, lowercased, as a lookup set.

**Why `options` too, confirmed against the same production source Task 5 checked:** `DalFacetReader::GROUPED_AGGREGATIONS = ['properties', 'options']` — a real shop's facet layer folds variant-defining option values (Colour, Size) into the *same* `properties.<Group>` namespace `PropertyClaimExtractor` scans, because both come from Shopware's property-group system. `PropertyClaimExtractor` therefore can and will extract a genuine option value (e.g. "Blue") as a candidate claim. If this class checked only `$card->properties`, a model correctly restating a real option value — something it already legitimately knows via `ToolProductSummary`'s `options` key — would be flagged as an invented attribute: the exact "assertion fires on correct behaviour" failure ruling R85 exists to prevent, arriving through a new door. Checking both fields is what keeps that door closed.

- [ ] **Step 1: Implement directly (no separate test — see note above; Task 7 exercises it)**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Every value a reply is entitled to state as a product attribute, lowercased for a
 * case-insensitive lookup — the {@see ProseAudit::unbackedProperties()} analogue of
 * {@see BackedFigures::inCents()}.
 *
 * Checks both `properties` and `options`, not `properties` alone: a real shop's facet layer
 * (`DalFacetReader::GROUPED_AGGREGATIONS`) merges variant option values (Colour, Size) into the same
 * `properties.<Group>` namespace {@see PropertyClaimExtractor} scans, so a genuine option value is a
 * candidate claim {@see PropertyClaimExtractor} can and will extract. A value the shopper introduced
 * themselves is exempted separately, directly in {@see ProseAudit::unbackedProperties()}, because
 * that check needs the original claimed string rather than a pre-built set.
 */
final class BackedPropertyValues
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $rendered
     *
     * @return array<string, true> keyed by lowercased value, so a lookup is array_key_exists
     */
    public static function of(array $rendered): array
    {
        $values = [];

        foreach ($rendered as $card) {
            foreach ($card->properties as $groupValues) {
                foreach ($groupValues as $value) {
                    $values[mb_strtolower($value)] = true;
                }
            }

            foreach ($card->options as $value) {
                $values[mb_strtolower($value)] = true;
            }
        }

        return $values;
    }
}
```

- [ ] **Step 2: Commit alongside Task 7** (this class has no independent test to run yet — the commit happens together with Task 7's, see that task's Step 5).

---

### Task 7: `ProseAudit::unbackedProperties()`

**Files:**
- Modify: `src/Core/Grounding/ProseAudit.php`
- Test: `tests/Core/Grounding/ProseAuditPropertyTest.php` (new)

**Interfaces:**
- Consumes: `PropertyClaimExtractor` (Task 5), `BackedPropertyValues` (Task 6).
- Produces: `ProseAudit::unbackedProperties(string $prose, list<ProductCard> $rendered, string $shopperMessage, FacetSet $facets): list<string>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ProseAudit;

final class ProseAuditPropertyTest extends TestCase
{
    private function facets(): FacetSet
    {
        // `properties.` prefix, matching the real convention PropertyClaimExtractor now requires
        // (Task 5) — both FixtureFacetBuilder and DalFacetReader namespace true property-group
        // facets this way.
        return new FacetSet([
            new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon']),
            new Facet('properties.Colour', FacetType::Terms, ['Blue']),
        ]);
    }

    /**
     * @param array<string, list<string>> $properties
     * @param array<string, string>       $options
     */
    private function card(array $properties, array $options = []): ProductCard
    {
        return new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            options: $options,
            properties: $properties,
        );
    }

    public function testAClaimTheCardBacksIsNotFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedProperties(
            'It is made of Merino.',
            [$this->card(['Material' => ['Merino']])],
            '',
            $this->facets(),
        );

        self::assertSame([], $unbacked);
    }

    public function testAClaimNoRenderedCardBacksIsFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedProperties(
            'It is made of Nylon.',
            [$this->card(['Material' => ['Merino']])],
            '',
            $this->facets(),
        );

        self::assertSame(['Nylon'], $unbacked);
    }

    public function testAValueTheShopperIntroducedIsNotFlagged(): void
    {
        // Ruling R85's exemption, applied to attribute claims: restating the shopper's own words is
        // not a claim by the model.
        $unbacked = (new ProseAudit())->unbackedProperties(
            'I searched for Nylon items as you asked, but found none in stock.',
            [$this->card(['Material' => ['Merino']])],
            'do you have anything in Nylon?',
            $this->facets(),
        );

        self::assertSame([], $unbacked);
    }

    public function testARealOptionValueIsNotFlagged(): void
    {
        // "Blue" lives in this card's `options` (a variant selection), not its `properties` — but a
        // real shop's facet layer merges both under the same properties.* namespace (Task 6's
        // finding, DalFacetReader::GROUPED_AGGREGATIONS), so PropertyClaimExtractor can extract it as
        // a candidate claim. Correctly restating a real option value must never be flagged.
        $unbacked = (new ProseAudit())->unbackedProperties(
            'It comes in Blue.',
            [$this->card(properties: [], options: ['Colour' => 'Blue'])],
            '',
            $this->facets(),
        );

        self::assertSame([], $unbacked);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Grounding/ProseAuditPropertyTest.php`
Expected: FAIL — `unbackedProperties()` does not exist.

- [ ] **Step 3: Implement**

Add to `src/Core/Grounding/ProseAudit.php` (new constructor-promoted collaborator + method), and add `use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;` to its imports:

```php
final readonly class ProseAudit
{
    public function __construct(
        private CurrencyFigureExtractor $currencyFigures = new CurrencyFigureExtractor(),
        private AvailabilityClaimExtractor $availabilityClaims = new AvailabilityClaimExtractor(),
        private PropertyClaimExtractor $propertyClaims = new PropertyClaimExtractor(),
    ) {}

    // ... existing unbackedPrices() / unbackedAvailabilityClaims() unchanged ...

    /**
     * Attribute claims (material, and similar) in the prose that no rendered card's own `properties`
     * backs — the {@see self::unbackedPrices()} analogue for {@see PropertyClaimExtractor}'s closed
     * vocabulary. Same R85-style exemption: a value the shopper introduced themselves is not a claim
     * by the model.
     *
     * @param list<\Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard> $rendered
     *
     * @return list<string>
     */
    public function unbackedProperties(
        string $prose,
        array $rendered,
        string $shopperMessage,
        FacetSet $facets,
    ): array {
        $backed = BackedPropertyValues::of($rendered);
        $claims = $this->propertyClaims->extract($prose, $facets);
        $shopperLower = mb_strtolower($shopperMessage);

        return array_values(array_filter($claims, static function (string $claim) use ($backed, $shopperLower): bool {
            if (\array_key_exists(mb_strtolower($claim), $backed)) {
                return false;
            }

            return !str_contains($shopperLower, mb_strtolower($claim));
        }));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Grounding/ProseAuditPropertyTest.php tests/Core/Grounding/ProseAuditTest.php tests/Core/Grounding/ProseAuditAvailabilityTest.php`
Expected: PASS (existing `ProseAudit` tests unaffected — new constructor param has a default).

- [ ] **Step 5: Commit**

```bash
git add src/Core/Grounding/BackedPropertyValues.php src/Core/Grounding/ProseAudit.php tests/Core/Grounding/ProseAuditPropertyTest.php
git commit -m "feat: audit attribute claims against the shop's own vocabulary, mirroring the price audit"
```

---

### Task 8: `FactRenderer::unbackedPropertiesInProse()`

**Files:**
- Modify: `src/Core/Grounding/FactRenderer.php`
- Test: `tests/Core/Grounding/FactRendererUnbackedPropertiesTest.php` (new)

**Interfaces:**
- Consumes: `ProseAudit::unbackedProperties()` (Task 7).
- Produces: `FactRenderer::unbackedPropertiesInProse(string $prose, FacetSet $facets): list<string>` and `FactRenderer::unbackedProperties(): list<string>` — exact shape of the existing `unbackedPricesInProse()`/`unbackedPrices()` pair. **This closes the gap Task 2 left open** (`AssistantRunner.php`'s call to `$this->bundle->renderer->unbackedProperties()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class FactRendererUnbackedPropertiesTest extends TestCase
{
    public function testFlagsAndRecordsAnUnbackedPropertyClaim(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            properties: ['Material' => ['Merino']],
        );

        $renderer->registerRetrieved([$card]);
        $renderer->render(['fx-001']);

        $facets = new FacetSet([new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon'])]);
        $unbacked = $renderer->unbackedPropertiesInProse('It is Nylon.', $facets);

        self::assertSame(['Nylon'], $unbacked);
        self::assertSame(['Nylon'], $renderer->unbackedProperties());
    }

    public function testABackedClaimFlagsNothing(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            properties: ['Material' => ['Merino']],
        );

        $renderer->registerRetrieved([$card]);
        $renderer->render(['fx-001']);

        $facets = new FacetSet([new Facet('properties.Material', FacetType::Terms, ['Merino'])]);

        self::assertSame([], $renderer->unbackedPropertiesInProse('It is Merino.', $facets));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Grounding/FactRendererUnbackedPropertiesTest.php`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Implement**

Add to `src/Core/Grounding/FactRenderer.php` — a new private property, and two new public methods, mirroring `unbackedPrices()`/`unbackedPricesInProse()` exactly:

```php
    /** @var list<string> */
    private array $unbackedProperties = [];
```

(placed next to the existing `private array $unbackedPrices = [];`)

```php
    /**
     * @return list<string> the values the last {@see self::unbackedPropertiesInProse()} call found
     */
    public function unbackedProperties(): array
    {
        return $this->unbackedProperties;
    }

    /**
     * Attribute claims in the prose that no rendered card's own `properties` backs — the
     * {@see self::unbackedPricesInProse()} analogue for {@see \Swag\AssistantStarterKit\Core\Grounding\PropertyClaimExtractor}'s
     * closed vocabulary.
     *
     * @return list<string>
     */
    public function unbackedPropertiesInProse(string $prose, \Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet $facets): array
    {
        $unbacked = $this->proseAudit->unbackedProperties(
            $prose,
            array_values($this->renderedCards),
            $this->shopperMessage,
            $facets,
        );

        $this->unbackedProperties = $unbacked;

        if ($unbacked !== []) {
            $this->trace->record('claims.audit', ['unbackedPropertyClaims' => $unbacked]);
        }

        return $unbacked;
    }
```

(Use a proper `use` import for `FacetSet` at the top of the file instead of the fully-qualified name above — written inline here only for clarity of exactly which type is referenced.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Grounding/FactRendererUnbackedPropertiesTest.php tests/Core/Grounding/FactRendererTest.php tests/Core/Grounding/FactRendererUnbackedPricesTest.php`
Expected: PASS

Now that `FactRenderer::unbackedProperties()` exists, `AssistantRunner.php`'s Task 2 edit type-checks. Run: `vendor/bin/phpunit tests/Core/Agent/` and `composer typecheck` to confirm the whole `Core/Agent` slice is consistent.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Grounding/FactRenderer.php tests/Core/Grounding/FactRendererUnbackedPropertiesTest.php
git commit -m "feat: FactRenderer::unbackedPropertiesInProse(), closing the Warnings wiring gap from Task 2"
```

---

### Task 9: Wire the property audit into the live turn (`GroundingOutputProcessor`, `AssistantAgentFactory`, controller, storefront)

**Files:**
- Modify: `src/Core/Agent/GroundingOutputProcessor.php`
- Modify: `src/Core/Agent/AssistantAgentFactory.php`
- Modify: `src/Controller/AssistantController.php`
- Modify: `src/Resources/app/storefront/src/assistant/render.js`
- Modify: `src/Resources/views/storefront/component/assistant/panel.html.twig`
- Modify: `src/Resources/snippet/swag-assistant.en.json`, `src/Resources/snippet/swag-assistant.de.json`
- Test: extend `tests/Core/Agent/` processor test coverage (see Step 1) + a `render.js` behavior check via the existing JS test pattern

This is one plumbing deliverable end to end — the warning reaching the browser — so it is one task rather than five, per the plan's own task-sizing rule (a reviewer would not meaningfully approve "wire the trace" while rejecting "wire the banner").

**Interfaces:**
- Consumes: `FactRenderer::unbackedPropertiesInProse()` (Task 8), `Warnings` (Task 1).
- Produces: the live `POST /assistant/chat` response gains `warnings.unbackedPropertyClaims`; a live reply making an unbacked attribute claim shows the same class of banner `warningPrice`/`warningAvailability` already show.

**Existing call sites that must keep compiling unmodified:** `tests/Core/Agent/GroundingOutputProcessorTest.php` (via its `processBatches()` helper, line 57), `tests/Core/Agent/GroundingOutputProcessorOncePerTurnTest.php` (3 call sites: lines 78, 101, 123), `tests/Core/Agent/OutputProcessorOrderTest.php` (line 75) all construct `new GroundingOutputProcessor($renderer, $trace)` with 2 args today, testing behavior (invention detection, once-per-turn dedup, processor ordering) unrelated to the property audit. Rather than touch all three files, `$facets` gets a default value (`new FacetSet()`, which makes `PropertyClaimExtractor::extract()` find nothing — a correct, inert default for tests that don't exercise it) so none of those 6 call sites need changing.

- [ ] **Step 1: Write the failing test** — add to the real existing file, `tests/Core/Agent/GroundingOutputProcessorTest.php`, reusing its own `processBatches()` helper pattern exactly (it builds cards from `FixtureCommerceGateway`/`tests/Fixtures/catalog.json`, calls `registerRetrieved()`, wraps prose in `new Output('gpt-x', new TextResult($prose), new MessageBag())`):

```php
    public function testFlagsAnUnbackedPropertyClaimWhenFacetsAreProvided(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $product = $gateway->product('fx-017', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        // fx-017's own properties (per the fixture) do not include "Nylon" — see catalog.json.
        $facets = new FacetSet([new Facet('properties.Material', FacetType::Terms, ['Nylon'])]);
        $output = new Output('gpt-x', new TextResult('It is made of Nylon.'), new MessageBag());

        (new GroundingOutputProcessor($renderer, $trace, $facets))->processOutput($output);

        self::assertSame(['Nylon'], $renderer->unbackedProperties());
    }
```

Add `use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;` and `use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;` and `use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;` to this file's existing imports. Before finalizing, check `tests/Fixtures/catalog.json` for `fx-017`'s actual `properties` value (`GroundingOutputProcessorTest.php`'s own existing tests already use `fx-017` as a known-good fixture id) — if it happens to already carry a "Nylon" value, swap the asserted-unbacked value in this test for one that genuinely is not in that product's properties.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testFlagsAnUnbackedPropertyClaimWhenFacetsAreProvided`
Expected: FAIL — `GroundingOutputProcessor`'s constructor does not accept a third `FacetSet` argument yet.

- [ ] **Step 3: Implement the wiring**

`src/Core/Agent/GroundingOutputProcessor.php` — add the collaborator (with a default so the 6 unrelated existing call sites above keep compiling) and the call. Add `use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;` to its imports:

```php
    public function __construct(
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly FacetSet $facets = new FacetSet(),
    ) {}
```

In `processOutput()`, right after the existing `unbackedAvailabilityInProse()` call:

```php
        $this->renderer->unbackedPricesInProse($text, RetrievedPassages::from($this->trace));
        $this->renderer->unbackedAvailabilityInProse($text);
        $this->renderer->unbackedPropertiesInProse($text, $this->facets);
```

`src/Core/Agent/AssistantAgentFactory.php` — capture the `FacetSet` once and reuse it for both the vocabulary render and the processor (currently it's only captured implicitly inside the `CatalogVocabulary::renderWithStats($facetProbe->probe($config->scope))` call). Replace:

```php
        $vocabularyStats = CatalogVocabulary::renderWithStats($facetProbe->probe($config->scope));
```

with:

```php
        $facets = $facetProbe->probe($config->scope);
        $vocabularyStats = CatalogVocabulary::renderWithStats($facets);
```

(`FacetProbe` caches per scope for the life of the request — this second call inside `SearchProductsTool` itself, and now this one, are cache reads, not new gateway hits; see `FacetProbe`'s own docblock.)

And replace:

```php
            outputProcessors: [$toolProcessor, new GroundingOutputProcessor($renderer, $trace)],
```

with:

```php
            outputProcessors: [$toolProcessor, new GroundingOutputProcessor($renderer, $trace, $facets)],
```

`src/Controller/AssistantController.php::warnings()` — read from `$turn->warnings` and add the third key:

```php
    private static function warnings(AssistantTurn $turn): array
    {
        return [
            'unbackedPrices' => $turn->warnings->unbackedPrices,
            'unbackedAvailabilityClaims' => $turn->warnings->unbackedAvailabilityClaims,
            'unbackedPropertyClaims' => $turn->warnings->unbackedPropertyClaims,
        ];
    }
```

`src/Resources/app/storefront/src/assistant/render.js::buildWarning()` — add the third check, lowest priority (a wrong material claim is a smaller failure than a sold-out item claimed available, or a wrong price):

```js
function buildWarning(warnings, translations) {
    const availability = warnings?.unbackedAvailabilityClaims ?? [];
    const prices = warnings?.unbackedPrices ?? [];
    const properties = warnings?.unbackedPropertyClaims ?? [];

    if (availability.length === 0 && prices.length === 0 && properties.length === 0) {
        return null;
    }

    const el = document.createElement('p');
    el.className = 'swag-assistant-warning';
    el.setAttribute('role', 'note');

    if (availability.length > 0) {
        el.textContent = translations.warningAvailability ?? '';
    } else if (prices.length > 0) {
        el.textContent = translations.warningPrice ?? '';
    } else {
        el.textContent = translations.warningProperty ?? '';
    }

    return el;
}
```

`src/Resources/views/storefront/component/assistant/panel.html.twig` — add one line to the translations JSON block, right after `warningPrice`:

```twig
                    warningPrice: 'swagAssistant.warning.price'|trans,
                    warningProperty: 'swagAssistant.warning.property'|trans,
```

`src/Resources/snippet/swag-assistant.en.json`, inside the existing `"warning"` object:

```json
    "warning": {
      "availability": "This is currently out of stock. The card below is correct.",
      "price": "The prices on the cards are the ones that apply.",
      "property": "The material and attribute details on the card below are the ones that apply."
    },
```

`src/Resources/snippet/swag-assistant.de.json`:

```json
    "warning": {
      "availability": "Dieser Artikel ist nicht auf Lager. Die Karte unten ist korrekt.",
      "price": "Es gelten die Preise auf den Karten.",
      "property": "Es gelten die Material- und Merkmalsangaben auf der Karte unten."
    },
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Core/Agent/ tests/Controller/`
Expected: PASS — including all 6 pre-existing `GroundingOutputProcessor*`/`OutputProcessorOrderTest` call sites, unmodified, now resolving `$facets` via its default. Then run the full unit suite to confirm nothing elsewhere broke: `composer test`.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Agent/GroundingOutputProcessor.php src/Core/Agent/AssistantAgentFactory.php src/Controller/AssistantController.php src/Resources/app/storefront/src/assistant/render.js src/Resources/views/storefront/component/assistant/panel.html.twig src/Resources/snippet/swag-assistant.en.json src/Resources/snippet/swag-assistant.de.json tests/Core/Agent/
git commit -m "feat: surface unbacked attribute claims as a shopper-facing warning, end to end"
```

---

### Task 10: System prompt — the model must only state properties it was given

**Files:**
- Modify: `src/Core/Prompt/SystemPrompt.php`
- Test: check for an existing `tests/Core/Prompt/SystemPromptTest.php` first (`grep -rln "SystemPrompt::build" tests/`); add an assertion there if found, otherwise this task's verification is the journey in Task 12 (a prompt-only change has no independently testable unit behavior beyond string containment).

**Interfaces:**
- Produces: `SystemPrompt::RULES` gains one paragraph. No signature change.

- [ ] **Step 1: (if a SystemPromptTest exists) write a failing containment assertion**

```php
    public function testTellsTheModelToOnlyStateGivenProperties(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('material', mb_strtolower($prompt));
    }
```

If no such test file exists, skip to Step 3 directly — do not create a new test file solely for one string-containment check; Task 12's journey is the real verification for this change.

- [ ] **Step 2: Run test to verify it fails (if Step 1 applies)**

Run: `vendor/bin/phpunit --filter testTellsTheModelToOnlyStateGivenProperties`
Expected: FAIL.

- [ ] **Step 3: Implement**

In `src/Core/Prompt/SystemPrompt.php::RULES`, insert a new paragraph directly after the existing "Never state a price, stock level..." paragraph:

```
        Never state a price, stock level, delivery time or URL yourself. The shop renders every
        such figure from its own records, so name products in plain words and leave all numbers to
        the shop.

        A tool result may include a product's properties (material, and similar attributes). State
        only a value that was actually returned to you. Never infer, generalise or add an adjective
        the shop did not give you — if a product's properties do not say "waterproof", do not call
        it waterproof, even if that seems like a reasonable guess.
```

- [ ] **Step 4: Run test to verify it passes (if applicable)**

Run: `vendor/bin/phpunit --filter testTellsTheModelToOnlyStateGivenProperties`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Prompt/SystemPrompt.php
git commit -m "feat: tell the model to only state product properties it was actually given"
```

---

### Task 11: Register the `no_unbacked_property_claim_in_prose` assertion

**Files:**
- Create: `src/Eval/Assertion/NoUnbackedPropertyClaimInProse.php`
- Modify: `src/Eval/Assertion/AssertionRegistry.php`
- Test: `tests/Eval/Assertion/NoUnbackedPropertyClaimInProseTest.php` (new, mirrors `NoUnbackedPriceInProseTest.php` exactly)

**Interfaces:**
- Consumes: `AssistantTurn::$warnings->unbackedPropertyClaims` (Task 1/9).
- Produces: journey files can declare `'no_unbacked_property_claim_in_prose' => []` in their `assertions` block.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\Warnings;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\NoUnbackedPropertyClaimInProse;

final class NoUnbackedPropertyClaimInProseTest extends TestCase
{
    public function testPassesWhenNothingIsUnbacked(): void
    {
        $turn = new AssistantTurn(prose: 'It is Merino.', cards: [], outcome: 'product_shown', warnings: new Warnings());

        $result = (new NoUnbackedPropertyClaimInProse())->evaluate($turn, new TraceRecorder(), []);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenAClaimIsUnbacked(): void
    {
        $turn = new AssistantTurn(
            prose: 'It is Nylon.',
            cards: [],
            outcome: 'product_shown',
            warnings: new Warnings(unbackedPropertyClaims: ['Nylon']),
        );

        $result = (new NoUnbackedPropertyClaimInProse())->evaluate($turn, new TraceRecorder(), []);

        self::assertFalse($result->passed);
    }
}
```

(`AssertionResult` is `final readonly class AssertionResult { public function __construct(public string $name, public bool $passed, public string $detail) {} }` — confirmed against `src/Eval/AssertionResult.php`, so `->passed` above is exact.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Eval/Assertion/NoUnbackedPropertyClaimInProseTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The {@see NoUnbackedPriceInProse} analogue for attribute claims: reads
 * {@see AssistantTurn::$warnings}'s {@see \Swag\AssistantStarterKit\Core\Agent\Warnings::$unbackedPropertyClaims},
 * already computed by {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::unbackedPropertiesInProse()}.
 * Never re-parses the prose itself.
 */
final class NoUnbackedPropertyClaimInProse implements Assertion
{
    public function name(): string
    {
        return 'no_unbacked_property_claim_in_prose';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        if ([] === $turn->warnings->unbackedPropertyClaims) {
            return new AssertionResult($this->name(), true, 'no unbacked attribute claim in the prose');
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'prose stated an attribute no rendered card backs: %s',
                implode(', ', $turn->warnings->unbackedPropertyClaims),
            ),
        );
    }

    public function isSafety(): bool
    {
        return true;
    }
}
```

Add to `src/Eval/Assertion/AssertionRegistry.php`'s `match()`:

```php
            'no_unbacked_property_claim_in_prose' => new NoUnbackedPropertyClaimInProse(),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Eval/Assertion/NoUnbackedPropertyClaimInProseTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/Assertion/NoUnbackedPropertyClaimInProse.php src/Eval/Assertion/AssertionRegistry.php tests/Eval/Assertion/NoUnbackedPropertyClaimInProseTest.php
git commit -m "feat: register no_unbacked_property_claim_in_prose as a journey assertion"
```

---

### Task 12: Red-first journeys — `property_grounded_claim` and `property_claim_unbacked`

**Files:**
- Create: `tests/Journeys/property_grounded_claim.php`
- Create: `tests/Journeys/property_claim_unbacked.php`

**Interfaces:**
- Consumes: all of Phase 1 above — this is the end-to-end proof it works together.

**Which fixture, and which real products.** `tests/Eval/JourneyEvalTest.php` runs every journey against the default twelve-product catalogue (`tests/Fixtures/catalog.json`) unless the journey declares a `catalogue` requirement — omitted here, same as `price_constraint.php`, so these run on the cheap default rather than opting into the larger fashion/large catalogues. Checked that fixture directly (`tests/Fixtures/catalog.json` — the exact file `GetProductToolTest`/`GroundingOutputProcessorTest` also use): only **one** of the twelve products carries non-variant `properties` — `fx-021`, "Disc Brake Pad Set (Shimano)", `{'Brake system': ['Disc'], 'Manufacturer': ['Shimano']}`. Every other product's `properties` is either empty or only duplicates its variant `options` (Colour/Size). Both journeys below are written against `fx-021` for that reason, not the Trail Jersey.

- [ ] **Step 1: Write both journey fixtures**, following the exact format of `tests/Journeys/price_constraint.php`:

`tests/Journeys/property_grounded_claim.php`:

```php
<?php

declare(strict_types=1);

return [
    'id' => 'property_grounded_claim',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'who makes the disc brake pad set?',
        'beginner' => 'what brand are the disc brake pads?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
    ],
];
```

`tests/Journeys/property_claim_unbacked.php` — an adversarial case constructed to tempt the model into inferring an attribute it was not given. `fx-021` only carries `Brake system` and `Manufacturer` — no compound/material data — and brake pads are commonly described by compound (organic/sintered) in the real world, which is exactly the kind of plausible-sounding, unbacked detail a model is tempted to add:

```php
<?php

declare(strict_types=1);

return [
    'id' => 'property_claim_unbacked',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what compound are the disc brake pads made of — organic or sintered?',
        'beginner' => 'what material are the disc brake pads?',
    ],
    'config' => [],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
    ],
];
```

- [ ] **Step 2: Run only these two journeys and read the result before touching anything else**

Run: `vendor/bin/phpunit --group eval --filter "property_grounded_claim|property_claim_unbacked"`

If the eval harness filters by journey id rather than test method name, instead use whatever mechanism `JourneyEvalTest.php`/`JourneyCatalogueTest.php` provides for running a single journey — check that file first if the filter above does not select them. Expected: **both pass**, since Phase 1's audit is the safety net, not a generator — a model that infers "waterproof" without being told is still free to do so; the audit's job is only to make that failure visible (`no_unbacked_property_claim_in_prose` would fail), not to prevent the model from trying. If `property_claim_unbacked` fails here, that is real information: either the system prompt addition (Task 10) needs strengthening, or the audit itself has a gap — do not treat a red result as a fixture problem without checking which it is first.

- [ ] **Step 3: N/A** — these are eval journeys, not unit tests; there is no separate "implementation" step, Phase 1's own tasks are the implementation.

- [ ] **Step 4: Re-run to confirm the observed result is stable**

Run the same command from Step 2 again. `runs: 3` means each archetype fires 3 times — a flaky result here (pass sometimes, fail sometimes) is itself a finding worth recording before moving on, not something to average away.

- [ ] **Step 5: Commit**

```bash
git add tests/Journeys/property_grounded_claim.php tests/Journeys/property_claim_unbacked.php
git commit -m "test: add red-first journeys covering grounded and adversarial property claims"
```

---

### Task 13: Phase 1 checkpoint — full regression, before/after

**Files:** none (verification only)

- [ ] **Step 1: Run the full unit suite**

Run: `composer test`
Expected: every test green, including all Phase 1 additions. Compare the total test/assertion count against the plan's stated baseline (984 tests, 18854 assertions, captured 2026-08-28) — the new count should be baseline **plus** every new test file added in Tasks 1-11, with zero baseline tests newly failing.

- [ ] **Step 2: Run the full quality gate**

Run: `composer quality`
Expected: clean, same as the plan's captured baseline (format/lint/typecheck pass; jscpd's pre-existing 92 clones and the one ignored security advisory are accepted baseline noise, not new failures — if jscpd's clone count grew because of duplication this plan introduced, that is a real finding to fix, not to wave through).

- [ ] **Step 3: Run the full eval suite against the default catalogue, and compare against the real captured baseline**

**Baseline already captured, 2026-08-28, before Task 1 started:** `composer test:eval` → **29 journeys, 18 ran, 18 passed, 11 skipped, 0 failures.** The 11 skips are the `scale_*`/`fashion_*` journeys, which require `ASSISTANT_EVAL_CATALOG=large`/`=fashion` and are correctly skipped when that's unset — not a gap for this comparison, since every journey this plan adds also targets the default catalogue.

Run: `composer test:eval`
Expected: **18 ran, 18 passed, 11 skipped still — plus the two new journeys, so 20 ran / 20 passed / 11 skipped.** Any journey that passed in the captured baseline and does not pass now is a real regression in retrieval, grounding, or existing behavior — not something to explain away. `property_claim_unbacked`'s result specifically needs a human read per Task 12 Step 2's note, not just a green checkmark.

- [ ] **Step 4: Run the eval suite against the large and fashion catalogues too**

These were the 11 skipped journeys above, plus this plan's own new journeys re-run against real scale and real attribute diversity — the gap identified after measuring `PropertyClaimExtractor`'s actual target data (`docs/superpowers/plans/2026-08-28-recommendation-explanation-and-comparison.md`'s own Task 5 finding: 62 facet fields / 623 values on `large`, `properties.Material` genuinely present with 13 values on `fashion`). This is real evidence Phase 1 works at scale and against richer attribute data, not just against the one brake-pad product the default catalogue happens to have.

Run: `ASSISTANT_EVAL_CATALOG=large composer test:eval`
Expected: the `scale_*` journeys (previously skipped) now run and pass, same as they did before this plan touched anything — Phase 1's changes must not regress large-catalogue retrieval. `property_grounded_claim`/`property_claim_unbacked` also run against `large` (they declare no catalogue requirement, so they are not skipped here) — confirm they still pass against real scale, not just the twelve-product fixture they were written against.

Run: `ASSISTANT_EVAL_CATALOG=fashion composer test:eval`
Expected: the `fashion_*` journeys (previously skipped) still pass. The two new property journeys also run here — this is the run that actually exercises a `properties.Material`-bearing product, the richer case the default catalogue's single brake pad only thinly covers.

- [ ] **Step 5: N/A** (verification-only checkpoint, no code change; nothing to commit unless Steps 3-4 surface a regression, in which case fix it as its own small commit before proceeding to Phase 2)

---

## PHASE 2A — Journey 2: explain why a product was retrieved

### Task 14: `AssistantConfig::enableMatchReasons` + journey allow-list

**Files:**
- Modify: `src/Core/Policy/AssistantConfig.php`
- Modify: `src/Eval/JourneyConfig.php`
- Test: extend whatever existing test covers `AssistantConfig`'s defaults (`grep -rln "class AssistantConfig" tests/` to find it) with one assertion that the new flag defaults to `false`.

**Interfaces:**
- Produces: `AssistantConfig::$enableMatchReasons` (`bool`, default `false` — off by default, the same posture new capabilities take here). `JourneyConfig` accepts `'enableMatchReasons'` as a journey config key.

- [ ] **Step 1: Write the failing test**

```php
    public function testMatchReasonsAreOffByDefault(): void
    {
        self::assertFalse((new AssistantConfig())->enableMatchReasons);
    }
```

(add to whichever existing `AssistantConfig`-covering test file `grep` finds; if none exists, this single assertion does not warrant a new file — fold it into Task 15's `MatchReasonsTest.php` instead as a one-line sanity check on the default, and skip creating a separate file here.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testMatchReasonsAreOffByDefault`
Expected: FAIL — property does not exist.

- [ ] **Step 3: Implement**

In `src/Core/Policy/AssistantConfig.php`'s constructor parameter list, add (near `enableEscalation`, following the same `bool ... = true/false` style — default `false` here since this is a new, not-yet-proven capability, unlike `enableAddToCart`/`enableEscalation` which default on):

```php
        public bool $enableMatchReasons = false,
```

In `src/Eval/JourneyConfig.php`, add `'enableMatchReasons'` to the allow-list array and thread it through:

```php
        $unknown = array_diff(array_keys($journey->config), [
            'blockedProductIds',
            'enableEscalation',
            'escalationUrl',
            'embeddingModel',
            'enableMatchReasons',
        ]);
```

and in the returned `AssistantConfig`:

```php
        return new AssistantConfig(
            scope: new CatalogScope(blockedProductIds: $blockedProductIds),
            enableEscalation: (bool) ($journey->config['enableEscalation'] ?? true),
            escalationUrl: (string) ($journey->config['escalationUrl'] ?? ''),
            salesChannelId: ShopInfoFixture::SALES_CHANNEL_ID,
            embeddingModel: (string) ($journey->config['embeddingModel'] ?? ''),
            enableMatchReasons: (bool) ($journey->config['enableMatchReasons'] ?? false),
        );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testMatchReasonsAreOffByDefault` (or the equivalent inline in `MatchReasonsTest.php` if folded there per Step 1's note)
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Policy/AssistantConfig.php src/Eval/JourneyConfig.php
git commit -m "feat: add enableMatchReasons capability flag, off by default"
```

---

### Task 15: `MatchReasons` + wiring into `SearchProductsTool`

**Files:**
- Create: `src/Core/Tool/MatchReasons.php`
- Modify: `src/Core/Tool/SearchProductsTool.php`
- Modify: `src/Core/Tool/ToolProductSummary.php` (accept an optional reasons map)
- Test: `tests/Core/Tool/MatchReasonsTest.php` (new)

**Interfaces:**
- Consumes: `MergedCandidates::byTerm()` (existing, `src/Core/Retrieval/MergedCandidates.php`), `ProductCard::isInStock()` (existing).
- Produces: `MatchReasons::of(list<ProductCard> $returned, array<string, list<ProductCard>> $candidatesByTerm): array<string, list<string>>` keyed by product id. `ToolProductSummary::of(list<ProductCard> $cards, array<string, list<string>> $reasons = []): list<array{..., reasons?: list<string>}>` — `reasons` key present only for a card with a non-empty list, and only when the caller passes a non-empty `$reasons` map at all (keeps the shape identical to today when the capability is off, matching the file's existing `note?`/`families?` optional-field convention).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\MatchReasons;

final class MatchReasonsTest extends TestCase
{
    private function card(string $id, int $stock): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Product ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/' . $id,
            imageUrl: null,
        );
    }

    public function testAttributesTheFirstMatchingTermWhenMoreThanOneTermWasSearched(): void
    {
        $dress = $this->card('fx-dress', 5);
        $suit = $this->card('fx-suit', 5);

        $reasons = MatchReasons::of([$dress, $suit], [
            'occasion dress' => [$dress],
            'occasion suit' => [$suit],
        ]);

        self::assertContains('matched_term:occasion dress', $reasons['fx-dress']);
        self::assertContains('matched_term:occasion suit', $reasons['fx-suit']);
    }

    public function testAddsNoTermReasonForASingleTermSearch(): void
    {
        $card = $this->card('fx-001', 5);

        $reasons = MatchReasons::of([$card], ['jersey' => [$card]]);

        self::assertNotContains('matched_term:jersey', $reasons['fx-001']);
    }

    public function testFlagsInStockProducts(): void
    {
        $inStock = $this->card('fx-in', 5);
        $outOfStock = $this->card('fx-out', 0);

        $reasons = MatchReasons::of([$inStock, $outOfStock], []);

        self::assertContains('in_stock', $reasons['fx-in']);
        self::assertNotContains('in_stock', $reasons['fx-out']);
    }

    public function testFlagsTheOnlyMatchWhenExactlyOneProductWasReturned(): void
    {
        $card = $this->card('fx-001', 5);

        $reasons = MatchReasons::of([$card], []);

        self::assertContains('only_match', $reasons['fx-001']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/MatchReasonsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `MatchReasons`**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Deterministic, code-computed reason codes for why a returned product was shown — the retrieval
 * mechanics the model has no visibility into today, never a personalization or preference signal
 * (there is no shopper profile anywhere in this codebase, and this class must not become one).
 *
 * Reuses the `reasonCode` convention {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter}
 * already established, rather than inventing a new claim shape. No audit is needed for these values
 * ({@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit} gains nothing new here): they are
 * closed, code-based signals the pipeline computed and handed over, not open claims the model could
 * misstate — the one exception, `in_stock`, is already covered by the existing
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unbackedAvailabilityClaims()} audit.
 *
 * Deliberately excludes anything already guaranteed by construction: `QueryBuilder` applies the
 * model's own `priceMax`/`priceMin` as hard filters, so "this fits the stated budget" is true for
 * every result and needs no reason code.
 */
final class MatchReasons
{
    private function __construct() {}

    /**
     * @param list<ProductCard>                $returned
     * @param array<string, list<ProductCard>> $candidatesByTerm each search term's own candidate
     *                                                            window, keyed by the term the model
     *                                                            sent — see {@see \Swag\AssistantStarterKit\Core\Retrieval\MergedCandidates::byTerm()}
     *
     * @return array<string, list<string>> reason codes keyed by product id
     */
    public static function of(array $returned, array $candidatesByTerm): array
    {
        $reasons = [];

        foreach ($returned as $card) {
            $codes = [];

            if (\count($candidatesByTerm) > 1) {
                foreach ($candidatesByTerm as $term => $cards) {
                    if (self::containsId($cards, $card->id)) {
                        $codes[] = 'matched_term:' . $term;

                        break;
                    }
                }
            }

            if ($card->isInStock()) {
                $codes[] = 'in_stock';
            }

            if (1 === \count($returned)) {
                $codes[] = 'only_match';
            }

            $reasons[$card->id] = $codes;
        }

        return $reasons;
    }

    /** @param list<ProductCard> $cards */
    private static function containsId(array $cards, string $id): bool
    {
        foreach ($cards as $card) {
            if ($card->id === $id) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Tool/MatchReasonsTest.php`
Expected: PASS

- [ ] **Step 5: Commit `MatchReasons` alone first**

```bash
git add src/Core/Tool/MatchReasons.php tests/Core/Tool/MatchReasonsTest.php
git commit -m "feat: add MatchReasons, deterministic reason codes for why a product was retrieved"
```

- [ ] **Step 6: Widen `ToolProductSummary::of()` to accept the reasons map** — write the failing test first:

```php
    public function testIncludesReasonsWhenProvided(): void
    {
        $card = /* same ProductCard construction pattern as this file's other tests */;

        $result = ToolProductSummary::of([$card], ['fx-001' => ['in_stock']]);

        self::assertSame(['in_stock'], $result[0]['reasons']);
    }

    public function testOmitsReasonsKeyWhenNoneProvided(): void
    {
        $card = /* same pattern */;

        $result = ToolProductSummary::of([$card]);

        self::assertArrayNotHasKey('reasons', $result[0]);
    }
```

Add to `tests/Core/Tool/ToolProductSummaryTest.php` (from Task 4). Run it, confirm it fails, then implement:

```php
    /**
     * @param list<ProductCard>              $cards
     * @param array<string, list<string>> $reasons reason codes keyed by product id, from
     *                                              {@see MatchReasons::of()} — empty unless
     *                                              enableMatchReasons is on
     *
     * @return list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, reasons?: list<string>}>
     */
    public static function of(array $cards, array $reasons = []): array
    {
        return array_map(static function (ProductCard $card) use ($reasons): array {
            $summary = [
                'id' => $card->id,
                'name' => $card->name,
                'options' => $card->options,
                'properties' => BoundedProperties::of($card->properties),
            ];

            $codes = $reasons[$card->id] ?? [];
            if ($codes !== []) {
                $summary['reasons'] = $codes;
            }

            return $summary;
        }, $cards);
    }
```

Run: `vendor/bin/phpunit tests/Core/Tool/ToolProductSummaryTest.php`
Expected: PASS.

- [ ] **Step 7: Wire into `SearchProductsTool`**

In `src/Core/Tool/SearchProductsTool.php`, after the existing line:

```php
        $returned = FamilyDiversifier::of($survivors, $requestedLimit);
```

add:

```php
        $matchReasons = $this->config->enableMatchReasons
            ? MatchReasons::of($returned, MergedCandidates::byTerm($candidates))
            : [];
```

and change the `'products' => ToolProductSummary::of($returned),` line inside the `$result` array to:

```php
            'products' => ToolProductSummary::of($returned, $matchReasons),
```

`MergedCandidates` is already imported in `SearchProductsTool.php` (`use Swag\AssistantStarterKit\Core\Retrieval\MergedCandidates;` — confirmed; the file already calls `MergedCandidates::firstNote()` and `MergedCandidates::anySaturated()`), so `byTerm()` needs no new import.

Also add a trace record right after computing `$matchReasons`, for merchant-side auditability, consistent with this file's existing style of recording every stage:

```php
        if ($matchReasons !== []) {
            $this->trace->record('match_reasons', ['reasons' => $matchReasons]);
        }
```

- [ ] **Step 8: Run the full tool test suite**

Run: `vendor/bin/phpunit tests/Core/Tool/`
Expected: PASS — every existing `SearchProductsTool*Test.php` still passes (the new field is additive and off by default via `enableMatchReasons: false`).

- [ ] **Step 9: Commit**

```bash
git add src/Core/Tool/ToolProductSummary.php src/Core/Tool/SearchProductsTool.php tests/Core/Tool/ToolProductSummaryTest.php
git commit -m "feat: wire MatchReasons into search_products, gated behind enableMatchReasons"
```

---

### Task 16: System prompt addition + `why_matched_term` journey

**Files:**
- Modify: `src/Core/Prompt/SystemPrompt.php`
- Create: `tests/Journeys/why_matched_term.php`

**Interfaces:**
- Consumes: Task 15's `reasons` field.

- [ ] **Step 1: Add a short rule paragraph**

In `SystemPrompt::RULES`, directly after the new properties paragraph added in Task 10:

```
        A tool result may also include reason codes for why a product was shown (for example, that
        it matched one of your search terms, or that it is in stock). You may mention these plainly
        in your own words. Never state a reason that was not given to you.
```

- [ ] **Step 2: N/A** — same reasoning as Task 10 Step 1: no independently-testable unit behavior beyond string containment; Step 3's journey is the real check.

- [ ] **Step 3: Write the journey**

`tests/Journeys/why_matched_term.php`, using a multi-term query so `matched_term:*` codes are actually produced (`MatchReasons::of()` only adds them when more than one term was searched). Written against the default twelve-product catalogue, using two real, distinct products from it — `fx-004` "Commuter Glove" and `fx-031` "Winter Mudguard Set" — the same way Task 12 grounds itself in real fixture products rather than ones from a different, opt-in catalogue:

```php
<?php

declare(strict_types=1);

return [
    'id' => 'why_matched_term',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'wet commute coming up — show me gloves or a mudguard set',
        'beginner' => 'it is going to be wet on my ride in, need gloves or a mudguard',
    ],
    'config' => [
        'enableMatchReasons' => true,
    ],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
```

- [ ] **Step 4: Run it**

Run: `vendor/bin/phpunit --group eval --filter why_matched_term` (or the journey-catalogue-appropriate filter mechanism, per Task 12 Step 2's note)
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Prompt/SystemPrompt.php tests/Journeys/why_matched_term.php
git commit -m "feat: let the model explain retrieval reasons in plain words, with a journey covering it"
```

---

### Task 17: Phase 2a checkpoint

**Files:** none (verification only)

- [ ] **Step 1: `composer test`** — expect green, count grows by Tasks 14-16's new tests.
- [ ] **Step 2: `composer quality`** — expect clean, same baseline as Task 13.
- [ ] **Step 3: `composer test:eval`**, default catalogue, including `why_matched_term` — expect **19 ran / 19 passed / 11 skipped** (Task 13's 18+2 baseline, plus this task's one new journey). Record the pass/fail table. Deliberately narrower than Tasks 13/22: this checkpoint stays on the default catalogue only, since `MatchReasons` is pure retrieval-result post-processing with no new facet-scanning code path (unlike Task 5's `PropertyClaimExtractor`) — the large/fashion sweep at Task 13 already covers the shared foundation this task builds on, and re-running it here would be cost without new risk to catch.
- [ ] **Step 4/5: N/A** — fix inline as its own commit if Step 3 regresses anything.

---

## PHASE 2B — Journey 3: compare products

### Task 18: `AssistantConfig::enableCompareProducts` + journey allow-list + service registration scaffold

**Files:**
- Modify: `src/Core/Policy/AssistantConfig.php`
- Modify: `src/Eval/JourneyConfig.php`

**Interfaces:**
- Produces: `AssistantConfig::$enableCompareProducts` (`bool`, default `false`).

- [ ] **Step 1: Write the failing test** — add to the same `AssistantConfig`-covering test file as Task 14:

```php
    public function testCompareProductsIsOffByDefault(): void
    {
        self::assertFalse((new AssistantConfig())->enableCompareProducts);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testCompareProductsIsOffByDefault`
Expected: FAIL.

- [ ] **Step 3: Implement**

Add `public bool $enableCompareProducts = false,` to `AssistantConfig`'s constructor, next to `enableMatchReasons`.

In `JourneyConfig.php`, add `'enableCompareProducts'` to the allow-list array and thread it through the returned `AssistantConfig` the same way as Task 14's `enableMatchReasons`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testCompareProductsIsOffByDefault`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Policy/AssistantConfig.php src/Eval/JourneyConfig.php
git commit -m "feat: add enableCompareProducts capability flag, off by default"
```

---

### Task 19: `CompareProductsTool` + factory + registration

**Files:**
- Create: `src/Core/Tool/CompareProductsTool.php`
- Create: `src/Core/Tool/Factory/CompareProductsToolFactory.php`
- Modify: `src/Resources/config/services.xml`
- Modify: `src/Core/Agent/AssistantAgentFactory.php` (`withCoreToolsOnly()`'s grounded factory list, so eval journeys can reach it — mirrors `SearchProductsToolFactory`/`GetProductToolFactory`/`AddToCartToolFactory` already being listed there)
- Test: `tests/Core/Tool/CompareProductsToolTest.php` (new)

**Interfaces:**
- Consumes: `CommerceGatewayInterface::product()` (existing, same as `GetProductTool`), `BlocklistFilter::apply()` (existing), `FactRenderer::registerRetrieved()` (existing), `ToolProductSummary::of()` (Task 15's 2-arg form — pass no reasons here, comparison has none).
- Produces: tool `compare_products`, gated by `enableCompareProducts`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\CompareProductsTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class CompareProductsToolTest extends TestCase
{
    private function tool(): CompareProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();

        return new CompareProductsTool(
            $gateway,
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }

    public function testComparesTwoExistingProducts(): void
    {
        // fx-017 and fx-007 are known-good fixture ids, already relied on by
        // GroundingOutputProcessorTest against this same tests/Fixtures/catalog.json.
        $result = ($this->tool())(productIds: ['fx-017', 'fx-007']);

        self::assertCount(2, $result['products']);
    }

    public function testRejectsFewerThanTwoIds(): void
    {
        $this->expectException(ToolArgumentException::class);

        ($this->tool())(productIds: ['fx-017']);
    }

    public function testRejectsMoreThanFourIds(): void
    {
        $this->expectException(ToolArgumentException::class);

        ($this->tool())(productIds: ['fx-017', 'fx-007', 'fx-008', 'fx-014', 'fx-026']);
    }

    public function testNotesWhenSomeIdsDidNotResolve(): void
    {
        // fx-999 is the established "known-nonexistent" id already used the same way by
        // GetProductToolTest::testReportsAnUnknownProductInsteadOfInventingOne().
        $result = ($this->tool())(productIds: ['fx-017', 'fx-999']);

        self::assertCount(1, $result['products']);
        self::assertArrayHasKey('note', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/CompareProductsToolTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Compares 2 to 4 products the conversation already found, by their catalogue attributes. Bounded to
 * 4 the same way {@see SearchProductsTool::MAX_LIMIT} is: a rejection, not a coercion — a request for
 * more is refused, not silently truncated.
 *
 * No variant resolution: the ids this tool receives already identify a specific sellable unit (the
 * model obtained them from `search_products` or `get_product`, both of which resolved variants
 * already). Reuses {@see ToolProductSummary}'s shape as-is, so this tool needed zero new grounding
 * work beyond widening that shape once, in Phase 1.
 */
#[AsTool(
    name: 'compare_products',
    description: 'Compare 2 to 4 products already found by search_products or get_product, side by '
    . 'side, by their catalogue attributes. Returns each product\'s id, name, option values and '
    . 'properties (material and similar attributes) — never prices or stock, which the shop renders. '
    . 'State only a property value this tool actually returned; never infer or generalise a quality '
    . 'judgement.',
)]
final class CompareProductsTool
{
    private const MIN_PRODUCTS = 2;

    private const MAX_PRODUCTS = 4;

    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly BlocklistFilter $blocklist,
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param list<string> $productIds 2 to 4 product ids to compare, from ids this conversation already retrieved.
     *
     * @return array{products: list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>}>, total: int, note?: string}
     */
    public function __invoke(array $productIds): array
    {
        $productIds = Guard::boundedArray($productIds, self::MAX_PRODUCTS, 'product_ids') ?? [];

        if (\count($productIds) < self::MIN_PRODUCTS) {
            throw new ToolArgumentException(\sprintf(
                'Argument "product_ids" needs at least %d ids to compare.',
                self::MIN_PRODUCTS,
            ));
        }

        $cards = [];
        foreach ($productIds as $id) {
            $id = Guard::boundedString($id, 64, 'product_ids[]') ?? '';
            $card = $this->gateway->product($id, $this->config->scope);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        $filtered = $this->blocklist->apply($cards, $this->config->scope);
        $this->trace->record('blocklist.filter', ['stage' => 'post', 'removedIds' => $filtered['removed']]);

        $survivors = $filtered['cards'];
        $this->renderer->registerRetrieved($survivors);

        $result = [
            'products' => ToolProductSummary::of($survivors),
            'total' => \count($survivors),
        ];

        if (\count($survivors) < \count($productIds)) {
            $result['note'] = 'Some of those ids did not resolve to a product in this shop.';
        }

        return $result;
    }
}
```

`src/Core/Tool/Factory/CompareProductsToolFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\CompareProductsTool;

final readonly class CompareProductsToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->config->enableCompareProducts) {
            return null;
        }

        return new CompareProductsTool(
            $context->gateway,
            $context->blocklist,
            $context->renderer,
            $context->trace,
            $context->config,
        );
    }
}
```

In `src/Resources/config/services.xml`, add right after the existing `AddToCartToolFactory` registration (around line 183-184):

```xml
        <service id="Swag\AssistantStarterKit\Core\Tool\Factory\CompareProductsToolFactory">
            <tag name="swag_assistant.grounded_tool_factory"/>
        </service>
```

In `src/Core/Agent/AssistantAgentFactory.php::withCoreToolsOnly()`, add `new CompareProductsToolFactory()` to the grounded factories array (so the eval suite, the probe command, and any other caller of this method — not the storefront container, which reads the tagged service above — can also construct it):

```php
    public static function withCoreToolsOnly(?HttpClientInterface $http = null): self
    {
        return new self(
            [new EscalateToolFactory()],
            [
                new SearchProductsToolFactory(),
                new GetProductToolFactory(),
                new AddToCartToolFactory(),
                new CompareProductsToolFactory(),
            ],
            new SystemPromptProvider(),
            new SymfonyAiPlatform($http),
        );
    }
```

Add the `use Swag\AssistantStarterKit\Core\Tool\Factory\CompareProductsToolFactory;` import.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Tool/CompareProductsToolTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Core/Tool/CompareProductsTool.php src/Core/Tool/Factory/CompareProductsToolFactory.php src/Resources/config/services.xml src/Core/Agent/AssistantAgentFactory.php tests/Core/Tool/CompareProductsToolTest.php
git commit -m "feat: add compare_products tool, gated behind enableCompareProducts"
```

---

### Task 20: Card UI — spec chips

**Files:**
- Modify: `src/Controller/CardPayload.php`
- Modify: `src/Resources/app/storefront/src/assistant/render.js`
- Modify: `src/Resources/app/storefront/src/assistant/card.js`
- Modify: `src/Resources/app/storefront/src/scss/components/_card.scss`
- Test: `tests/js/spec-chips.test.js` (new, mirrors `price-basis.test.js`'s pattern exactly — DOM assembly stays untested per `tests/e2e/README.md`'s own rule, only the pure formatting function is unit-tested)

**Interfaces:**
- Consumes: `BoundedProperties::of()` (Task 3).
- Produces: `CardPayload::of()`'s array shape gains a `properties` key; `render.js` exports `formatSpecChips(card, maxChips = 2)`; `card.js`'s `buildCard()` renders the result under the product name.

- [ ] **Step 1: Write the failing test**

```js
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatSpecChips } from '../../src/Resources/app/storefront/src/assistant/render.js';

/*
 * `tests/e2e/README.md` keeps DOM assembly out of unit tests. This is the pure string function this
 * exception is for — the same shape as `formatPriceBasis`'s own test file.
 */

test('renders up to two property values joined by a middle dot', () => {
    const card = { properties: { Material: ['Merino', 'Nylon'], Fit: ['Regular'] } };

    assert.equal(formatSpecChips(card), 'Merino · Nylon');
});

test('a card with no properties renders nothing', () => {
    assert.equal(formatSpecChips({ properties: {} }), '');
    assert.equal(formatSpecChips({}), '');
});

test('a card from before this field existed renders nothing rather than throwing', () => {
    assert.equal(formatSpecChips({ properties: null }), '');
    assert.equal(formatSpecChips(undefined), '');
});

test('respects a custom chip limit', () => {
    const card = { properties: { Material: ['Merino', 'Nylon', 'Cotton'] } };

    assert.equal(formatSpecChips(card, 1), 'Merino');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test tests/js/spec-chips.test.js`
Expected: FAIL — `formatSpecChips` is not exported.

- [ ] **Step 3: Implement**

`src/Core/Tool/BoundedProperties` is model/PHP-side only — `CardPayload.php` needs its own use of the same bound. Add the import and one line to `src/Controller/CardPayload.php`:

```php
use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;
```

and inside `of()`'s mapped array, add after `'options' => $card->options,`:

```php
                'properties' => BoundedProperties::of($card->properties),
```

In `src/Resources/app/storefront/src/assistant/render.js`, add near `formatPriceBasis`:

```js
/** Two is enough to differentiate at a glance without crowding a 176px card. */
const MAX_SPEC_CHIPS = 2;

/**
 * A short "Merino · Waterproof" line for a card, built entirely from the `properties` the server
 * already sent — nothing here is read from the model's prose, same rule `formatPriceBasis` follows.
 */
export function formatSpecChips(card, maxChips = MAX_SPEC_CHIPS) {
    const properties = card?.properties;

    if (properties === null || typeof properties !== 'object' || Array.isArray(properties)) {
        return '';
    }

    const values = Object.values(properties)
        .flat()
        .filter((value) => typeof value === 'string' && value !== '');

    if (values.length === 0) {
        return '';
    }

    return values.slice(0, maxChips).join(' · ');
}
```

In `src/Resources/app/storefront/src/assistant/card.js`, `card.js:9` already reads `import { formatPriceBasis } from './render.js';` — widen that same line:

```js
import { formatPriceBasis, formatSpecChips } from './render.js';
```

In `buildCard()`, right after the existing options line:

```js
    const options = Object.values(card.options ?? {});
    if (options.length > 0) {
        info.appendChild(text('p', 'swag-assistant-card__options', options.join(' · ')));
    }

    const specs = formatSpecChips(card);
    if (specs !== '') {
        info.appendChild(text('p', 'swag-assistant-card__specs', specs));
    }
```

`src/Resources/app/storefront/src/scss/components/_card.scss:168-173` already groups the exact meta-text style this needs:

```scss
.swag-assistant-card__options,
.swag-assistant-card__delivery {
    margin: 3px 0 0;
    color: $swag-assistant-meta;
    font-size: $swag-assistant-font-micro;
    line-height: 1.35;
```

Join `.swag-assistant-card__specs` into that same selector group rather than writing a new rule with new values — the spec line is the same kind of quiet meta text `__options`/`__delivery` already are, not a new visual category:

```scss
.swag-assistant-card__options,
.swag-assistant-card__delivery,
.swag-assistant-card__specs {
    margin: 3px 0 0;
    color: $swag-assistant-meta;
    font-size: $swag-assistant-font-micro;
    line-height: 1.35;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node --test tests/js/spec-chips.test.js`
Expected: PASS. Also run the full JS suite to confirm nothing else broke: `node --test tests/js/`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/CardPayload.php src/Resources/app/storefront/src/assistant/render.js src/Resources/app/storefront/src/assistant/card.js src/Resources/app/storefront/src/scss/components/_card.scss tests/js/spec-chips.test.js
git commit -m "feat: render a compact material/attribute line on every product card"
```

---

### Task 21: `compare_two_products` journey

**Files:**
- Create: `tests/Journeys/compare_two_products.php`

**Interfaces:**
- Consumes: `compare_products` tool (Task 19), the property audit (Phase 1).

- [ ] **Step 1: Write the journey**

Uses `fx-021` "Disc Brake Pad Set (Shimano)" (the only twelve-product-catalogue item with real, non-variant `properties`) against `fx-011` "Discontinued Rim Brake Pad" (a real, related product with **no** `properties` data) — a natural "which brake pads" comparison, and a genuinely useful test: the model must state `fx-021`'s real Manufacturer/Brake system values while inventing nothing for `fx-011`, which has none to give:

```php
<?php

declare(strict_types=1);

return [
    'id' => 'compare_two_products',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'compare the disc brake pads and the rim brake pads for me',
        'beginner' => 'what is the difference between the disc brake pads and the rim brake pads?',
    ],
    'config' => [
        'enableCompareProducts' => true,
    ],
    'turns' => ['archetype'],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_property_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit --group eval --filter compare_two_products` (or the journey-catalogue filter mechanism, per Task 12's note)
Expected: PASS.

- [ ] **Step 3: N/A** — journey fixture, no separate implementation step.

- [ ] **Step 4: Re-run to confirm stability across all 3×2 runs.**

- [ ] **Step 5: Commit**

```bash
git add tests/Journeys/compare_two_products.php
git commit -m "test: add compare_two_products journey covering the compare_products tool end to end"
```

---

### Task 22: Final checkpoint — full before/after regression report

**Files:** none (verification only)

- [ ] **Step 1: `composer test`**

Expected: green. Report the final count against the plan's captured baseline (984 tests, 18854 assertions) — the delta is every test file this plan added across Tasks 1-21.

- [ ] **Step 2: `composer quality`**

Expected: clean, matching the captured baseline exactly (format/lint/typecheck pass, same pre-existing jscpd/security-advisory noise, no new duplication or issues introduced by this plan's own new files).

- [ ] **Step 3: `composer test:eval`, the default catalogue**

**Real captured baseline, 2026-08-28, before Task 1 started: 29 journeys, 18 ran, 18 passed, 11 skipped, 0 failures.** Every pre-existing journey (`blocked_item`, `cart_add`, ..., `vocabulary_not_inventory` — the full list from `tests/Journeys/`) plus all four new ones (`property_grounded_claim`, `property_claim_unbacked`, `why_matched_term`, `compare_two_products`).

Run: `composer test:eval`
Expected: **20 ran / 20 passed / 11 skipped.** This is the definitive before/after on the default catalogue: every journey that passed before Task 1 must still pass now, and all four new journeys must pass (or their genuine failure understood and either fixed or explicitly accepted as a known limitation, per Task 12 Step 2's note — never silently ignored).

- [ ] **Step 4: `composer test:eval`, large and fashion catalogues**

Closes the coverage gap the default-catalogue run leaves open: the 11 journeys skipped in Step 3 above, plus this plan's own new journeys, actually exercised against scale and against real attribute diversity — not assumed to work there.

Run: `ASSISTANT_EVAL_CATALOG=large composer test:eval`
Expected: the previously-skipped `scale_*` journeys now run and pass — no regression from anything Phase 1/2 touched (`PropertyClaimExtractor`'s facet scan, `FacetProbe`'s caching path, `MatchReasons`' extra pass over candidates). `property_grounded_claim`, `property_claim_unbacked`, `why_matched_term`, and `compare_two_products` also run here (they declare no catalogue requirement) — confirm they hold up against a real 62-field/623-value facet set, not just the twelve-product fixture they were written against.

Run: `ASSISTANT_EVAL_CATALOG=fashion composer test:eval`
Expected: the previously-skipped `fashion_*` journeys still pass, and the four new journeys run against a catalogue with genuine `properties.Material` diversity (confirmed present, 13 values, when this gap was first measured) — the strongest real evidence this feature does what Journeys 2 and 3 were asked to do, not just that it doesn't crash on one brake pad.

- [ ] **Step 5: N/A** — if Steps 1-4 are fully green, the feature is done. If anything regressed on any catalogue, fix it as its own commit and re-run the affected steps before considering the work finished.

---

## Notes for the executor

- **Task ordering within Phase 1 matters mechanically**, not just logically: Task 2 references `FactRenderer::unbackedProperties()`, which does not exist until Task 8. This is called out explicitly in both tasks' steps — do not run the full suite between Task 2 and Task 8, only the targeted test files each step names.
- **Two "N/A" steps appear repeatedly** (Tasks 10, 12, 16, 21, 22's Steps 3-5 in places) — these are journeys and checkpoints, which do not fit the write-test/implement/verify shape of a unit-level task. That is intentional, not a gap in the plan.
- **Every `composer test:eval` run costs real API time and money.** Tasks 13, 17, and 22 are the only points this plan calls for a full eval run; do not run it more often than that while executing individual tasks — targeted `vendor/bin/phpunit --group eval --filter <journey-id>` runs (Tasks 12, 16, 21) are cheaper and sufficient for verifying one new journey in isolation. **Tasks 13 and 22 each run three full sweeps** (default catalogue, `ASSISTANT_EVAL_CATALOG=large`, `ASSISTANT_EVAL_CATALOG=fashion`) — budget roughly 3x a single-catalogue run's time and cost for those two checkpoints specifically; Task 17 stays single-catalogue, see its own note for why.
