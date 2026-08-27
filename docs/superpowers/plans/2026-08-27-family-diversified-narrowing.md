# Family-Diversified Narrowing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a search matches far more products than the model asked for, show a family-diverse
selection instead of a plain relevance-ranked prefix — so 1,355 matching dresses render as 8 different
styles, not 2 styles' size runs.

**Architecture:** One new pure, static class (`FamilyDiversifier`) replaces the `array_slice()` that
today narrows `SearchProductsTool`'s survivor list to the model's requested limit. It runs after every
other filter (variant resolution, blocklist, `RedundantParentFilter`) so it only ever diversifies the
final, fully-resolved set. A new eval assertion (`rendered_family_spread`) measures the fix against the
real seeded fashion catalogue.

**Tech Stack:** PHP 8.2, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`

## Global Constraints

- PHP **8.2+**, `declare(strict_types=1)` everywhere.
- `composer run quality` must exit **0**: `mago fmt --check`, `mago lint`, `mago analyze`
  (`cyclomatic-complexity` threshold 10, `excessive-parameter-list` threshold 5, both error-level, summed
  per class), `check_file_length.php` (400 physical lines per file), `jscpd`, `composer-dependency-analyser`,
  `composer audit`.
- **Never run `vendor/bin/phpunit tests/Eval` (or `--group eval`) without checking with the user first** —
  `tests/bootstrap.php` loads `.env` and that command fires paid live-model turns. Task 4's verification
  step needs exactly one live eval run; flag it clearly before running.
- The deterministic suite (`vendor/bin/phpunit --exclude-group eval`) must stay green throughout, and grow
  by exactly the new tests this plan adds — nothing existing should need to change except the two docblocks
  named in Task 2.
- `FamilyDiversifier::of()`'s family key is `$card->parentId ?? $card->id` (design decision F3) —
  **deliberately different** from `RedundantParentFilter`'s and `TruncatedFamilies::groupByFamily()`'s
  notion of family (both exclude standalone products from "family" on purpose). Do not unify these; the
  spec explains why they must differ.
- No facet-based diversity dimension, no config, no `browse_categories`-style feature — v1 is family
  (`parentId`) only, per spec decision F1. Do not add either as a "while I'm here" improvement.

---

### Task 1: `FamilyDiversifier`

**Files:**
- Create: `src/Core/Tool/FamilyDiversifier.php`
- Test: `tests/Core/Tool/FamilyDiversifierTest.php`

**Interfaces:**
- Produces: `FamilyDiversifier::of(array $survivors, int $limit): array` — `list<ProductCard>` in,
  `list<ProductCard>` out, same shape as `RedundantParentFilter::apply()`.
- Produces: `FamilyDiversifier::familyKey(ProductCard $card): string` — public, so Task 3's assertion can
  reuse the exact same "what counts as one family" definition rather than re-deriving it.
- Consumes: `Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard` (existing, unchanged) — the fields
  used are `$parentId` (`?string`) and `$id` (`string`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Core/Tool/FamilyDiversifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier;

/**
 * `FamilyDiversifier::of()` — see `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`,
 * decision F2, for the algorithm this locks in: one card per family first (in existing relevance
 * order), then backfill from already-shown families if slots remain.
 */
final class FamilyDiversifierTest extends TestCase
{
    public function testASingleFamilyIsATrueNoOp(): void
    {
        // Five variants of one family, already in relevance order. With only one family,
        // diversifying has nothing to diversify — the output must equal a plain
        // array_slice(survivors, 0, limit), byte for byte.
        $cards = self::family('parent-a', 5);

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-a-1', 'parent-a-2'], self::ids($result));
    }

    public function testMultipleFamiliesAreDiversifiedBeforeAnyBackfill(): void
    {
        // Family A's five variants rank first (adjacent, same name), then family B's two.
        // Requesting 3 must surface both families, not exhaust family A first.
        $cards = [...self::family('parent-a', 5), ...self::family('parent-b', 2)];

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-b-0', 'parent-a-1'], self::ids($result));
    }

    public function testBackfillNeverReturnsFewerThanArraySliceWould(): void
    {
        // Only two distinct families exist. Asking for 5 must still return 5 — the shopper
        // never sees fewer cards than plain top-N would have shown just because diversity
        // ran out (spec F2's explicit requirement).
        $cards = [...self::family('parent-a', 3), ...self::family('parent-b', 3)];

        $result = FamilyDiversifier::of($cards, 5);

        self::assertCount(5, $result);
        self::assertSame(
            ['parent-a-0', 'parent-b-0', 'parent-a-1', 'parent-b-1', 'parent-a-2'],
            self::ids($result),
        );
    }

    public function testStandaloneProductsAreEachTheirOwnFamily(): void
    {
        // Two products with no parentId are two different things, not "a family of one"
        // to be collapsed together — spec F3, deliberately unlike RedundantParentFilter's
        // and TruncatedFamilies' notion of family.
        $cards = [self::standalone('fx-001'), self::standalone('fx-002'), self::standalone('fx-003')];

        $result = FamilyDiversifier::of($cards, 2);

        self::assertSame(['fx-001', 'fx-002'], self::ids($result));
    }

    public function testFamilyKeyIsParentIdOrOwnId(): void
    {
        $variant = self::family('parent-a', 1)[0];
        $standalone = self::standalone('fx-001');

        self::assertSame('parent-a', FamilyDiversifier::familyKey($variant));
        self::assertSame('fx-001', FamilyDiversifier::familyKey($standalone));
    }

    public function testEmptySurvivorsReturnsEmpty(): void
    {
        self::assertSame([], FamilyDiversifier::of([], 5));
    }

    public function testALimitOfZeroReturnsEmpty(): void
    {
        self::assertSame([], FamilyDiversifier::of(self::family('parent-a', 3), 0));
    }

    public function testFewerSurvivorsThanTheLimitReturnsAllOfThem(): void
    {
        $cards = self::family('parent-a', 2);

        $result = FamilyDiversifier::of($cards, 5);

        self::assertSame(['parent-a-0', 'parent-a-1'], self::ids($result));
    }

    /** @return list<ProductCard> */
    private static function family(string $parentId, int $count): array
    {
        $cards = [];

        for ($i = 0; $i < $count; ++$i) {
            $cards[] = new ProductCard(
                id: $parentId . '-' . $i,
                parentId: $parentId,
                name: 'Card',
                description: null,
                price: 10.0,
                currency: 'EUR',
                stock: 5,
                stockSource: StockSource::Variant,
                deliveryTime: null,
                url: '/detail/' . $parentId . '-' . $i,
                imageUrl: null,
            );
        }

        return $cards;
    }

    private static function standalone(string $id): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Card',
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Core/Tool/FamilyDiversifierTest.php`
Expected: FAIL with "Class ... FamilyDiversifier not found".

- [ ] **Step 3: Write the implementation**

Create `src/Core/Tool/FamilyDiversifier.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Picks a family-diverse subset of the narrowed shortlist instead of a plain relevance-ranked prefix.
 *
 * ## The failure it fixes
 *
 * Relevance ranking has no notion of "one product vs. its size run": same-family variants share a name
 * and description, so they rank adjacently. Measured live, `docs/superpowers/reports/
 * 2026-08-27-fashion-catalogue-seeded.md`: *"show me dresses"* and *"show me yoga clothes"* both
 * returned cards that were *"multiple variants of the same families"* rather than a spread of styles,
 * even though the search matched over a thousand dresses. See
 * `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md` for the full measurement.
 *
 * ## The algorithm (spec decision F2)
 *
 * Two passes over `$survivors` in its existing relevance order. Pass one keeps the first card of every
 * distinct family until `$limit` cards are kept or `$survivors` runs out — this is the diversifying
 * pass. Pass two, only if pass one filled fewer than `$limit`, appends the remaining (already-
 * represented-family) cards in their original order until the limit is reached. A shopper never sees
 * fewer cards than a plain `array_slice($survivors, 0, $limit)` would have shown — diversifying never
 * makes the shortlist smaller, only more varied.
 *
 * A single-family input is a true no-op: pass one keeps every card in its original order (one key,
 * nothing to interleave), so the output equals `array_slice($survivors, 0, $limit)` exactly.
 *
 * ## Where this runs, and why it must be here
 *
 * After variant resolution, the blocklist, and {@see \Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter}
 * — never earlier. Diversifying before those would risk selecting a "diverse" set that then loses
 * members to blocklisting or redundant-parent removal, undermining the diversity work before the
 * shopper ever sees it (spec decision F4). It is the last reordering step before
 * {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} slices to the model's requested limit —
 * see that class and {@see \Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave}'s docblocks,
 * both updated alongside this class for exactly that reason.
 */
final class FamilyDiversifier
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $survivors relevance-ranked, already fully resolved and filtered
     *
     * @return list<ProductCard> at most `$limit` cards, drawn only from `$survivors`
     */
    public static function of(array $survivors, int $limit): array
    {
        $seenFamilies = [];
        $firstPass = [];
        $leftover = [];

        foreach ($survivors as $card) {
            $key = self::familyKey($card);

            if (isset($seenFamilies[$key])) {
                $leftover[] = $card;

                continue;
            }

            $seenFamilies[$key] = true;
            $firstPass[] = $card;
        }

        if (\count($firstPass) >= $limit) {
            return \array_slice($firstPass, offset: 0, length: $limit);
        }

        $needed = $limit - \count($firstPass);

        return [...$firstPass, ...\array_slice($leftover, offset: 0, length: $needed)];
    }

    /**
     * What counts as "one family" for diversification: a family of variants shares `parentId`; a
     * standalone product (no parent) is its own family of one, keyed by its own id.
     *
     * **Deliberately not** the same notion {@see \Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter}
     * or {@see TruncatedFamilies::groupByFamily()} use — both of those exclude a standalone product from
     * "family" on purpose, because grouping it would invent a family the catalogue does not have for
     * their purposes (superseded-parent removal, truncation disclosure). Here, two different standalone
     * products are still two different things worth showing separately, so each needs its own key
     * (spec decision F3).
     */
    public static function familyKey(ProductCard $card): string
    {
        return $card->parentId ?? $card->id;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Core/Tool/FamilyDiversifierTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Tool/FamilyDiversifier.php tests/Core/Tool/FamilyDiversifierTest.php
git commit -m "feat(retrieval): family-diverse narrowing instead of a plain relevance prefix"
```

---

### Task 2: Wire `FamilyDiversifier` into `SearchProductsTool`

**Files:**
- Modify: `src/Core/Tool/SearchProductsTool.php:292-295`
- Modify: `src/Core/Retrieval/CandidateInterleave.php` (class docblock)
- Test: run existing `tests/Core/Tool/SearchProductsToolNarrowingTest.php`,
  `tests/Core/Tool/SearchProductsToolWithheldTest.php`, `tests/Core/Retrieval/CandidateInterleaveTest.php`
  (no new file — this task proves the pinned regression tests still pass, not new behaviour)

**Interfaces:**
- Consumes: `FamilyDiversifier::of()` (Task 1), same namespace as `SearchProductsTool` (`Core\Tool`) so no
  new `use` import is needed.

`FamilyDiversifier` lives in the same namespace as `SearchProductsTool` (`Swag\AssistantStarterKit\Core\Tool`),
so referencing it by its bare class name needs no new `use` statement.

- [ ] **Step 1: Replace the narrowing line**

In `src/Core/Tool/SearchProductsTool.php`, find:

```php
        // Narrowing happens HERE, not in retrieval. Everything above needed the full
        // candidate window to be correct — VariantResolver cannot disambiguate a set of
        // one — and nothing below can recover a unit that retrieval already dropped.
        $returned = \array_slice($survivors, offset: 0, length: $requestedLimit);
```

Replace with:

```php
        // Narrowing happens HERE, not in retrieval. Everything above needed the full
        // candidate window to be correct — VariantResolver cannot disambiguate a set of
        // one — and nothing below can recover a unit that retrieval already dropped.
        //
        // FamilyDiversifier, not a plain array_slice: relevance ranking clusters same-family
        // variants adjacently (they share a name/description), so a plain prefix here could
        // be one product's whole size run rather than a spread of styles. See its own
        // docblock, and CandidateInterleave's — this is the reordering step that class's
        // docblock now names explicitly.
        $returned = FamilyDiversifier::of($survivors, $requestedLimit);
```

- [ ] **Step 2: Correct `CandidateInterleave`'s now-false ordering claim**

In `src/Core/Retrieval/CandidateInterleave.php`, find the `## Order is the contract` section (its exact
current text is quoted in full in `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`
under "What was verified while designing this"):

```php
 * ## Order is the contract
 *
 * Nothing downstream re-sorts: `VariantResolver` substitutes in place, `BlocklistFilter` and
 * `RedundantParentFilter` only remove, and narrowing takes a prefix. So the order this produces is the
 * order of the cards.
 */
```

Replace with:

```php
 * ## Order was the whole contract — until FamilyDiversifier
 *
 * `VariantResolver` substitutes in place, `BlocklistFilter` and `RedundantParentFilter` only remove —
 * neither re-sorts, so the order this class produces survives both untouched. The one exception, added
 * 2026-08-27: {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier}, the step right before the
 * final slice, deliberately reorders survivors by family. It still treats this class's per-term balance
 * as its *starting* order — it only demotes same-family duplicates behind other families' cards, never
 * reorders across terms on its own initiative — but "the order this produces is the order of the cards"
 * is no longer literally true past that point. See `FamilyDiversifier`'s own docblock for why and how.
 */
```

- [ ] **Step 3: Run the pinned regression tests**

Run: `vendor/bin/phpunit tests/Core/Tool/SearchProductsToolNarrowingTest.php tests/Core/Tool/SearchProductsToolWithheldTest.php tests/Core/Retrieval/CandidateInterleaveTest.php`
Expected: PASS, all tests, unchanged count from before this task. `tests/Fixtures/catalog.json` (the
small fixture these tests use) has no family with more than four variants and few matching families per
term — `FamilyDiversifier` is a no-op on this catalogue's typical single-or-near-single-family search
results, which is exactly why these tests were expected to need no changes (confirmed by the spec's own
"What was verified" table — verify this expectation held, don't just assume it).

If any of these tests fail: read what changed carefully before editing the test — a failure here means
either the diversifier has a bug (fix `FamilyDiversifier`, not the test) or the small fixture has more
family overlap on a tested term than the spec assumed (in which case, understand exactly why before
touching anything, and prefer fixing `FamilyDiversifier` over weakening a pinned test).

- [ ] **Step 4: Run the full deterministic suite and quality gate**

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS, same test count as before this task (Task 1 already added its own tests; this task adds
none).

Run: `composer run quality`
Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Tool/SearchProductsTool.php src/Core/Retrieval/CandidateInterleave.php
git commit -m "feat(retrieval): narrow SearchProductsTool's shortlist through FamilyDiversifier"
```

---

### Task 3: The `rendered_family_spread` eval assertion

**Files:**
- Create: `src/Eval/Assertion/RenderedFamilySpread.php`
- Modify: `src/Eval/Assertion/AssertionRegistry.php`
- Test: `tests/Eval/Assertion/RenderedFamilySpreadTest.php`

**Interfaces:**
- Consumes: `Swag\AssistantStarterKit\Eval\Assertion` (interface: `name(): string`, `evaluate(AssistantTurn
  $turn, TraceRecorder $trace, array $expectations): AssertionResult`, `isSafety(): bool`),
  `Swag\AssistantStarterKit\Eval\AssertionResult` (readonly: `string $name, bool $passed, string $detail`),
  `FamilyDiversifier::familyKey()` (Task 1) — the exact same "what counts as one family" definition the
  narrowing fix itself uses, so this assertion tests the thing the fix actually changed.
- Produces: assertion key `'rendered_family_spread'`, config shape `['min' => int]` — the turn's rendered
  cards must span at least `min` distinct families (by `FamilyDiversifier::familyKey()`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Eval/Assertion/RenderedFamilySpreadTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\RenderedFamilySpread;

/**
 * `rendered_family_spread` — the rendered cards span at least `min` distinct families, not just
 * relevance-ranked variants of the same one or two products. See
 * `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`.
 */
final class RenderedFamilySpreadTest extends TestCase
{
    public function testPassesWhenTheFloorIsMet(): void
    {
        $turn = self::turnWithFamilies(['a', 'a', 'b', 'c']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 3]);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheFloorIsNotMet(): void
    {
        // Exactly the failure this assertion exists to catch: five cards, one family.
        $turn = self::turnWithFamilies(['a', 'a', 'a', 'a', 'a']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 3]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('1 distinct', $result->detail);
        self::assertStringContainsString('3', $result->detail);
    }

    public function testStandaloneProductsEachCountAsTheirOwnFamily(): void
    {
        $turn = self::turnWithFamilies([null, null, null]);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 3]);

        self::assertTrue($result->passed);
    }

    public function testFailsWithoutAnIntegerMin(): void
    {
        $turn = self::turnWithFamilies(['a', 'b']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), []);

        self::assertFalse($result->passed);
    }

    public function testFailsWithAMinBelowTwo(): void
    {
        // A floor of 1 (or 0) asserts nothing about spread — any non-empty render satisfies it.
        $turn = self::turnWithFamilies(['a']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 1]);

        self::assertFalse($result->passed);
    }

    public function testIsNotSafetyAndIsNamed(): void
    {
        self::assertFalse((new RenderedFamilySpread())->isSafety());
        self::assertSame('rendered_family_spread', (new RenderedFamilySpread())->name());
    }

    /**
     * @param list<string|null> $parentIds one card per entry; null means standalone
     */
    private static function turnWithFamilies(array $parentIds): AssistantTurn
    {
        $cards = [];

        foreach ($parentIds as $index => $parentId) {
            $id = $parentId === null ? 'standalone-' . $index : $parentId . '-' . $index;

            $cards[] = new ProductCard(
                id: $id,
                parentId: $parentId,
                name: 'Card',
                description: null,
                price: 10.0,
                currency: 'EUR',
                stock: 5,
                stockSource: $parentId === null ? StockSource::Product : StockSource::Variant,
                deliveryTime: null,
                url: '/detail/' . $id,
                imageUrl: null,
            );
        }

        return new AssistantTurn(prose: 'Here is what I found.', cards: $cards, outcome: 'product_shown');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Eval/Assertion/RenderedFamilySpreadTest.php`
Expected: FAIL with "Class ... RenderedFamilySpread not found".

- [ ] **Step 3: Write the implementation**

Create `src/Eval/Assertion/RenderedFamilySpread.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The rendered cards span at least this many distinct product families, not just relevance-ranked
 * size/colour variants of the same one or two products.
 *
 * ## The failure it catches
 *
 * Measured live, `docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md`: "show me dresses"
 * (1,355 matches) and "show me yoga clothes" both returned cards that were multiple variants of the
 * same families, not a spread of styles. Every existing assertion passed straight through that — the
 * cards are real products, correctly priced, correctly counted; nothing in the suite noticed the
 * shortlist was one product's size run wearing eight labels.
 *
 * Family identity reuses {@see FamilyDiversifier::familyKey()} — the exact same "what counts as one
 * family" definition the narrowing fix itself uses, so this assertion tests the thing the fix actually
 * changed, not a different notion of variety.
 */
final class RenderedFamilySpread implements Assertion
{
    public function name(): string
    {
        return 'rendered_family_spread';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $min = $expectations['min'] ?? null;

        if (!\is_int($min) || $min < 2) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "min" of 2 or more. A floor below two asserts nothing about spread.',
            );
        }

        $families = [];
        foreach ($turn->cards as $card) {
            $families[FamilyDiversifier::familyKey($card)] = true;
        }

        $spread = \count($families);

        if ($spread >= $min) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('%d distinct families among %d rendered card(s), at least %d required.', $spread, \count($turn->cards), $min),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'only %d distinct families among %d rendered card(s) — floor of %d not met; the shortlist may be size/colour repeats of the same product(s).',
                $spread,
                \count($turn->cards),
                $min,
            ),
        );
    }

    /**
     * Not safety: a low-diversity shortlist is a worse answer, not an unsafe one — same posture as
     * {@see RenderedIdsFromEach::isSafety()} for the same reason (model variance is real).
     */
    public function isSafety(): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Register the assertion**

In `src/Eval/Assertion/AssertionRegistry.php`, find the `match` block's last real case before `default`:

```php
            'no_unsupported_period_in_prose' => new NoUnsupportedPeriodInProse(),
            default => throw new \InvalidArgumentException(\sprintf(
```

Replace with:

```php
            'no_unsupported_period_in_prose' => new NoUnsupportedPeriodInProse(),
            'rendered_family_spread' => new RenderedFamilySpread(),
            default => throw new \InvalidArgumentException(\sprintf(
```

(If the file's exact case order differs slightly from what's shown here, add the new line as its own
`match` arm anywhere before `default` — order within the `match` doesn't matter, only that it's present
and before the `default` arm.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Eval/Assertion/RenderedFamilySpreadTest.php`
Expected: PASS, 6 tests.

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS, previous count + 15 (9 from Task 1, 6 from this task).

Run: `composer run quality`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Eval/Assertion/RenderedFamilySpread.php src/Eval/Assertion/AssertionRegistry.php \
  tests/Eval/Assertion/RenderedFamilySpreadTest.php
git commit -m "feat(eval): rendered_family_spread assertion for the diversified shortlist"
```

---

### Task 4: Wire the assertion into `fashion_many_matches`, measure the real threshold

This task has a manual, cost-incurring step (Step 3) — a live-model eval run. **Confirm with whoever is
running this plan before Step 3.** Everything else is free and local.

**Files:**
- Modify: `tests/Journeys/fashion_many_matches.php`

**Interfaces:**
- Consumes: `'rendered_family_spread'` (Task 3), config shape `['min' => int]`.

- [ ] **Step 1: Add the assertion with a provisional threshold**

In `tests/Journeys/fashion_many_matches.php`, find:

```php
    'assertions' => [
        'renders_at_least' => ['count' => 1],
        'questions_at_most' => ['max' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
```

Replace with:

```php
    'assertions' => [
        'renders_at_least' => ['count' => 1],
        'questions_at_most' => ['max' => 1],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
        // Provisional floor — Step 3 of the plan this journey was added under measures the
        // real number against the seeded shop and replaces this comment with the measurement,
        // the same way this file's own header already records `dress` matching 1,133 units.
        'rendered_family_spread' => ['min' => 3],
    ],
```

- [ ] **Step 2: Run the deterministic suite to confirm nothing else broke**

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS, same count as after Task 3 — this journey file is not covered by the deterministic
suite (journeys only run under `--group eval`), so this step is confirming the edit didn't break
anything else, not testing the assertion itself.

- [ ] **Step 3: Measure the real threshold — ASK BEFORE RUNNING, this costs money and calls a live model**

Run: `ASSISTANT_EVAL_CATALOG=fashion ASSISTANT_LLM_MODEL=google/gemini-3.7-flash vendor/bin/phpunit --group eval --testdox --filter fashion_many_matches`

Read the output. `rendered_family_spread`'s pass/fail line reports the actual distinct-family count
achieved on each of the 3×2 runs (see `RenderedFamilySpread::evaluate()`'s detail message). Two outcomes:

- **If it passes 2/3 or 3/3 on both archetypes** (this assertion is a quality assertion, `isSafety():
  false`, so 2/3 is a genuine pass per this project's own threshold convention): the provisional `min: 3`
  holds. Replace the "Provisional floor" comment with a measured one, e.g.:

  ```php
  // Measured 2026-08-27 on google/gemini-3.7-flash: X of Y rendered cards were distinct families
  // across 3 runs both archetypes, comfortably above this floor. See RenderedFamilySpread for what
  // "family" means here.
  'rendered_family_spread' => ['min' => 3],
  ```

  (fill in the real `X of Y` from the actual test output, not a guess)

- **If it fails**: read `RenderedFamilySpread`'s failure detail for the actual achieved spread. Lower
  `min` to a value with real margin below what was actually measured (e.g. if 3 runs showed spreads of
  4, 5, and 4, set `min: 3`, not `min: 5` — this assertion should have room for ordinary model-to-model
  variance, not be tuned to the exact ceiling of one run). Re-run this same command to confirm the new
  threshold holds, then write the measured comment as above. If the spread is consistently very low
  (e.g. 1-2, meaning the diversifier does not appear to be taking effect at all against the real
  fixture), stop and investigate Task 2's wiring before touching this threshold further — a low
  measurement here could mean the fixture's own ranking doesn't cluster families the way the real DAL
  does (worth noting in the eventual report either way), not necessarily a bug, but confirm before
  assuming it's fine.

- [ ] **Step 4: Commit**

```bash
git add tests/Journeys/fashion_many_matches.php
git commit -m "test(eval): measure the family-diversified shortlist against fashion_many_matches"
```

---

## Self-Review

**Spec coverage** (against `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`):

- F1 (family/`parentId` only, no facets, no config) — Task 1's `FamilyDiversifier` has no facet parameter
  and no config surface; Global Constraints explicitly forbids adding either.
- F2 (two-pass, order-preserving, backfill) — Task 1's algorithm and its 9 tests, specifically
  `testMultipleFamiliesAreDiversifiedBeforeAnyBackfill` and `testBackfillNeverReturnsFewerThanArraySliceWould`.
- F3 (family key is `parentId ?? id`, deliberately unlike `RedundantParentFilter`/`TruncatedFamilies`) —
  Task 1's `familyKey()` and `testStandaloneProductsAreEachTheirOwnFamily`; Global Constraints states the
  deliberate divergence explicitly so no implementer "fixes" it into alignment.
- F4 (runs after variant resolution/blocklist/`RedundantParentFilter`, not folded into `CandidateInterleave`)
  — Task 2 Step 1's insertion point, exactly where the spec's architecture diagram places it.
- F5 (same signature shape as `RedundantParentFilter::apply()`) — `FamilyDiversifier::of(array, int): array`.
- F6 (`CandidateInterleave` docblock and the `array_slice` comment both corrected in the same change) —
  Task 2 Steps 1 and 2.
- F7 (verification against the real seeded catalogue, not the fixture alone) — Task 4 Step 3's manual,
  live-model measurement step; the fixture-side eval assertion (Task 3) is explicitly scoped in its own
  docblock as proving the algorithm is family-aware, not proving the real-shop fix.

**Placeholder scan:** no TBD/TODO. The one deliberately open value (Task 4's `min` threshold) is filled
with a stated, reasoned provisional number (3) and an explicit, concrete procedure for replacing it with
a measured one — not left blank.

**Type consistency:** `FamilyDiversifier::of(array $survivors, int $limit): array` (Task 1) called
identically in Task 2's `SearchProductsTool` edit. `FamilyDiversifier::familyKey(ProductCard $card): string`
(Task 1) called identically in Task 3's `RenderedFamilySpread::evaluate()`. `RenderedFamilySpread`
implements `Assertion` (`name(): string`, `evaluate(AssistantTurn, TraceRecorder, array): AssertionResult`,
`isSafety(): bool`) matching the interface Task 3's own research confirmed, and its registry key
`'rendered_family_spread'` is identical between `AssertionRegistry.php` (Task 3 Step 4) and the journey
file (Task 4 Step 1).
