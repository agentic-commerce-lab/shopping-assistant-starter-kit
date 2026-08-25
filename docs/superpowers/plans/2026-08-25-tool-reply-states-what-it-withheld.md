# The Tool Reply States What It Withheld — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `SearchProductsTool`'s reply state what it held back — how many products matched, and which option values a family it truncated actually has — so the model can ask for a variant it was never told about.

**Architecture:** One new pure class groups the truncated tail into family summaries. Three new keys are added to the tool's reply, additively; no existing key changes meaning. Every number comes from data already computed for the `retrieve.narrow` trace event, so no gateway call and no interface change.

**Tech Stack:** PHP 8.2 target (running 8.5), PHPUnit 11, Mago (format + lint + analyze).

**Spec:** `docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md` — read it first. Decisions T3 (additive only), T4 (`matched` is a floor) and T6 (no figures) are the three a later reader is most likely to "improve" and the three that would cost the most.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- PHP 8.2 target. Cyclomatic complexity ≤ 10 and ≤ 10 methods **summed per class** — both are errors, not warnings, and neither is written in `mago.toml`. Moving branches into another method of the same class does not help.
- `composer run quality` fails only on `error[…]`; the repo carries ~90 lint and ~250 analyze warnings as its normal state. Judge a run by `grep 'error\['`.
- ≤ 5 parameters per function (`excessive-parameter-list`, an error). Promoted constructor properties count.
- Declare array shapes with `@phpstan-type` / `@phpstan-import-type` rather than `array<string, mixed>`, which makes every downstream field access `mixed`.
- Run `composer run format` **before** staging; `mago fmt` collapses multi-line calls and the `pre-commit` hook rejects unformatted staged PHP. The hook never runs `mago analyze`, so run `composer run quality` before believing a commit is clean.
- **T6, the one that matters most:** no `price`, `stock`, `deliveryTime` or `url` may enter the reply. `ToolProductSummary`'s docblock says *"Never widen this"* and it is right — a figure there lets the model quote what it did not earn. Task 3 Step 1 asserts this directly.
- **Change no constant.** `MAX_LIMIT` stays 8, `DEFAULT_LIMIT` 5, `MAX_CANDIDATES` 50, and `CatalogVocabularyBudget` is not touched.

## File Structure

| File | Responsibility |
|---|---|
| `src/Core/Tool/TruncatedFamilies.php` (create) | Groups the withheld cards into per-family summaries: name, how many were shown, how many exist, and the family's option values. Pure — cards in, arrays out. |
| `tests/Core/Tool/TruncatedFamiliesTest.php` (create) | The grouping, the truncation test, the value cap, and that no figure appears in the output. |
| `src/Core/Tool/SearchProductsTool.php` (modify) | Adds `matched`, `more` and `families` to the reply, and extends the `@return` docblock. |
| `tests/Core/Tool/SearchProductsToolWithheldTest.php` (create) | The reply's new keys, through the real tool against the small fixture. Named after its concern, following `SearchProductsToolLimitTest` and `SearchProductsToolNarrowingTest`. |
| `docs/superpowers/reports/2026-08-25-tool-reply-withheld-outcome.md` (create) | Task 4's deliverable: whether T7's criterion was met. |

`TruncatedFamilies` is its own class rather than a method on `SearchProductsTool` so the grouping is
testable without building the whole tool, and because that class is already this project's most
load-bearing one.

**Fixture families this plan tests against** — real data, verified 2026-08-25:

| Product | Name | Variants | Options |
|---|---|---|---|
| `fx-030` | Gravel Tyre 40c | 4 | Colour: Black, Tan × Size: 700x40, 650x47 |
| `fx-026` | Trail Jersey | 3 | Colour: Blue, Black × Size: M, L |

A search for `Gravel Tyre` at `limit: 2` therefore yields 4 survivors and 2 returned — a truncated
family, deterministically, with no large catalogue and no model.

---

### Task 1: Grouping the withheld cards

**Files:**
- Create: `src/Core/Tool/TruncatedFamilies.php`
- Test: `tests/Core/Tool/TruncatedFamiliesTest.php`

**Interfaces:**
- Consumes: `ProductCard` (existing: `$id`, `$parentId`, `$name`, `$options`).
- Produces:
  - `TruncatedFamilies::MAX_OPTION_VALUES = 50`
  - `TruncatedFamilies::of(array $survivors, array $returned): array` where both parameters are `list<ProductCard>` and the result is `list<FamilySummary>`
  - `@phpstan-type FamilySummary array{name: string, shown: int, variants: int, options: array<string, list<string>>, options_truncated?: bool}`

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Tool/TruncatedFamiliesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies;

/**
 * What the reply says about the variants it did NOT return.
 *
 * Measured before this existed: asked for `Size 30` of a thirty-variant family, the assistant
 * rendered variants 1 through 5 — 0/3 runs on both archetypes. Retrieval finds the right unit the
 * moment the option is supplied; the model never supplied it, because nothing had told it `Size 30`
 * exists. This class is what tells it.
 *
 * @see docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md
 */
final class TruncatedFamiliesTest extends TestCase
{
    /** @param array<string, string> $options */
    private static function variant(string $id, string $parentId, string $name, array $options): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $parentId,
            name: $name,
            description: null,
            price: 49.9,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: $options,
        );
    }

    /** @return list<ProductCard> */
    private static function tyreFamily(): array
    {
        return [
            self::variant('fx-030-black-700', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Black', 'Size' => '700x40']),
            self::variant('fx-030-black-650', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Black', 'Size' => '650x47']),
            self::variant('fx-030-tan-700', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Tan', 'Size' => '700x40']),
            self::variant('fx-030-tan-650', 'fx-030', 'Gravel Tyre 40c', ['Colour' => 'Tan', 'Size' => '650x47']),
        ];
    }

    public function testATruncatedFamilyReportsEveryOptionValueIncludingTheWithheldOnes(): void
    {
        $survivors = self::tyreFamily();

        $families = TruncatedFamilies::of($survivors, \array_slice($survivors, offset: 0, length: 2));

        self::assertCount(1, $families);
        self::assertSame('Gravel Tyre 40c', $families[0]['name']);
        self::assertSame(2, $families[0]['shown']);
        self::assertSame(4, $families[0]['variants']);

        // The point of the whole change: `Tan` and `650x47` are only on withheld variants, and the
        // model must still learn they exist.
        self::assertSame(['Black', 'Tan'], $families[0]['options']['Colour']);
        self::assertSame(['700x40', '650x47'], $families[0]['options']['Size']);
    }

    /** Nothing withheld, nothing to say. A summary here would be noise the model has to read. */
    public function testAFamilyReturnedWholeProducesNoSummary(): void
    {
        $survivors = self::tyreFamily();

        self::assertSame([], TruncatedFamilies::of($survivors, $survivors));
    }

    public function testTwoFamiliesAreSummarisedSeparately(): void
    {
        $survivors = [
            ...self::tyreFamily(),
            self::variant('fx-026-blue-m', 'fx-026', 'Trail Jersey', ['Colour' => 'Blue', 'Size' => 'M']),
            self::variant('fx-026-blue-l', 'fx-026', 'Trail Jersey', ['Colour' => 'Blue', 'Size' => 'L']),
        ];

        $families = TruncatedFamilies::of($survivors, [$survivors[0], $survivors[4]]);

        self::assertSame(['Gravel Tyre 40c', 'Trail Jersey'], array_column($families, 'name'));
        self::assertSame([1, 1], array_column($families, 'shown'));
        self::assertSame([4, 2], array_column($families, 'variants'));
    }

    /** A product with no parent is not a family, and cannot be truncated into one. */
    public function testStandaloneProductsAreNotFamilies(): void
    {
        $standalone = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Unnamed Chain Lube',
            description: null,
            price: 8.5,
            currency: 'EUR',
            stock: 20,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/fx-001',
            imageUrl: null,
        );

        self::assertSame([], TruncatedFamilies::of([$standalone, ...self::tyreFamily()], [$standalone]));
    }

    public function testTheOptionValueListIsCappedAndSaysSo(): void
    {
        $survivors = [];
        for ($i = 1; $i <= TruncatedFamilies::MAX_OPTION_VALUES + 10; ++$i) {
            $survivors[] = self::variant(
                \sprintf('fx-big-%03d', $i),
                'fx-big',
                'Endurance Bib Tights',
                ['Size' => 'Size ' . $i],
            );
        }

        $families = TruncatedFamilies::of($survivors, [$survivors[0]]);

        self::assertCount(TruncatedFamilies::MAX_OPTION_VALUES, $families[0]['options']['Size']);
        self::assertTrue($families[0]['options_truncated']);
        // The real count is still reported, so the model is not told the family is 50 wide.
        self::assertSame(TruncatedFamilies::MAX_OPTION_VALUES + 10, $families[0]['variants']);
    }

    /**
     * Spec decision T6, and the assertion this file exists to carry as much as the grouping.
     *
     * `ToolProductSummary`'s docblock says "Never widen this" about figures reaching the model, and
     * this class handles cards that carry a price and a stock figure. A summary that leaked either
     * would let the model quote a number it did not earn — the one failure the whole pipeline is
     * built to prevent.
     */
    public function testNoFigureEverReachesTheSummary(): void
    {
        $encoded = json_encode(
            TruncatedFamilies::of(self::tyreFamily(), [self::tyreFamily()[0]]),
            \JSON_THROW_ON_ERROR,
        );

        foreach (['price', 'stock', 'deliveryTime', 'url', '49.9', 'EUR'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, \sprintf('%s leaked', $forbidden));
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/TruncatedFamiliesTest.php`
Expected: FAIL — `Class "Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Core/Tool/TruncatedFamilies.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * What a search held back about a family it truncated: how many variants exist, and which option
 * values they carry.
 *
 * ## Why this exists
 *
 * {@see ToolProductSummary}'s docblock records the argument that put option values in the reply at
 * all: *"with opaque ids, identifying one of N candidates costs N tool calls, so any family larger
 * than the call budget is unanswerable."* This is the same arithmetic one level up. Return five of
 * thirty variants with no signal that twenty-five more exist, and a family larger than the RETURN
 * limit is unanswerable — measured, 0/3 runs on both archetypes of
 * `scale_family_beyond_window`.
 *
 * The same docblock also justified returning options on the grounds that *"the system prompt already
 * carries the catalogue's own vocabulary, including every option value."* Scale broke that: the
 * vocabulary block is capped at 1,500 characters and sends 66 of 106 values on a real 10,000-product
 * shop. This class removes the dependency rather than repairing it — the reply becomes
 * self-sufficient about options.
 *
 * ## What it must never carry
 *
 * No price, stock, delivery time or URL. The cards it reads have all four; the summaries it writes
 * have none. `ToolProductSummary` says *"Never widen this"* and the same rule holds here — see
 * `TruncatedFamiliesTest::testNoFigureEverReachesTheSummary`, which asserts it against the encoded
 * output rather than trusting the code to be read.
 *
 * @phpstan-type FamilySummary array{
 *     name: string,
 *     shown: int,
 *     variants: int,
 *     options: array<string, list<string>>,
 *     options_truncated?: bool,
 * }
 */
final class TruncatedFamilies
{
    /**
     * How many values of one option group the summary may list.
     *
     * Fifty, borrowing `DalCommerceGateway::FACET_VALUE_LIMIT`'s existing judgement about "enough to
     * be useful, bounded enough to send". Without a cap a thirty-variant problem becomes a
     * three-thousand-variant one, and the reply would be the thing bloating the context it was added
     * to inform.
     */
    public const MAX_OPTION_VALUES = 50;

    private function __construct() {}

    /**
     * @param list<ProductCard> $survivors everything retrieval and filtering produced
     * @param list<ProductCard> $returned  the narrowed slice the model actually receives
     *
     * @return list<FamilySummary>
     */
    public static function of(array $survivors, array $returned): array
    {
        $shownPerFamily = self::countByFamily($returned);
        $summaries = [];

        foreach (self::groupByFamily($survivors) as $familyId => $members) {
            $shown = $shownPerFamily[$familyId] ?? 0;

            // Only a family that lost members has anything to disclose. One returned whole is
            // already fully described by the `products` array beside it.
            if ($shown >= \count($members)) {
                continue;
            }

            $summaries[] = self::summarise($members, $shown);
        }

        return $summaries;
    }

    /**
     * Cards keyed by the family they belong to, standalone products excluded.
     *
     * A card with no `parentId` is its own product, not a family of one — grouping it would invent a
     * family the catalogue does not have and then report it as truncated.
     *
     * @param list<ProductCard> $cards
     *
     * @return array<string, list<ProductCard>>
     */
    private static function groupByFamily(array $cards): array
    {
        $families = [];

        foreach ($cards as $card) {
            if ($card->parentId === null) {
                continue;
            }

            $families[$card->parentId][] = $card;
        }

        return $families;
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return array<string, int>
     */
    private static function countByFamily(array $cards): array
    {
        $counts = [];

        foreach ($cards as $card) {
            if ($card->parentId === null) {
                continue;
            }

            $counts[$card->parentId] = ($counts[$card->parentId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param list<ProductCard> $members
     *
     * @return FamilySummary
     */
    private static function summarise(array $members, int $shown): array
    {
        $options = [];
        $truncated = false;

        foreach ($members as $member) {
            foreach ($member->options as $group => $value) {
                $known = $options[$group] ?? [];

                if (\in_array($value, $known, strict: true)) {
                    continue;
                }

                if (\count($known) >= self::MAX_OPTION_VALUES) {
                    $truncated = true;

                    continue;
                }

                $options[$group] = [...$known, $value];
            }
        }

        $summary = [
            // Every member of a family carries the family's name, so the first is as good as any.
            'name' => $members[0]->name ?? '',
            'shown' => $shown,
            'variants' => \count($members),
            'options' => $options,
        ];

        return $truncated ? [...$summary, 'options_truncated' => true] : $summary;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Tool/TruncatedFamiliesTest.php`
Expected: PASS (6 tests).

If `summarise()` trips `cyclomatic-complexity` (the class threshold is 10, summed across methods —
this design lands near 9), the fix is a second class for the option collection, not a pragma. Check
with `vendor/bin/mago lint src/Core/Tool/TruncatedFamilies.php`.

- [ ] **Step 5: Verify the gate**

Run: `vendor/bin/mago analyze src/Core/Tool/TruncatedFamilies.php tests/Core/Tool/TruncatedFamiliesTest.php`
Expected: `No issues found.` If `$members[0]->name ?? ''` reports a redundant coalesce, drop the `?? ''`
— a non-empty list's first element is provable to the analyzer here even though a variable index is not.

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS, unchanged count plus 6.

- [ ] **Step 6: Commit**

```bash
composer run format
composer run quality
git add src/Core/Tool/TruncatedFamilies.php tests/Core/Tool/TruncatedFamiliesTest.php
git commit -m "feat(tool): summarise the variants a search held back"
```

---

### Task 2: `matched` and `more`

**Files:**
- Modify: `src/Core/Tool/SearchProductsTool.php` — the `@return` docblock (currently line 147-152) and the `$result` array (currently line 337-341)
- Test: `tests/Core/Tool/SearchProductsToolWithheldTest.php` (create)

**Interfaces:**
- Consumes: `ProductQuery::retrievalLimit(): int` (existing), `$survivors` and `$cards` (existing locals in `__invoke()`).
- Produces: two new reply keys, `matched: int` and `more: bool`, present on every reply.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Tool/SearchProductsToolWithheldTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What the reply says about its own limits.
 *
 * Split out by concern, following `SearchProductsToolLimitTest` and
 * `SearchProductsToolNarrowingTest`. The concern here: phase B found the reply carries
 * `total => count($returned)`, so 500 matching products were reported as 5 with nothing saying
 * otherwise — a bounded answer that reads as a complete one.
 *
 * @see docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md
 */
final class SearchProductsToolWithheldTest extends TestCase
{
    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }

    public function testMatchedReportsWhatSurvivedRetrievalNotWhatWasReturned(): void
    {
        // Gravel Tyre 40c has four variants in the fixture; asking for two must still report four.
        $result = ($this->tool())(term: 'Gravel Tyre', limit: 2);

        self::assertSame(2, $result['total'], 'total keeps meaning the length of products (T3)');
        self::assertCount(2, $result['products']);
        self::assertSame(4, $result['matched']);
    }

    public function testAnUntruncatedSearchReportsMatchedEqualToTotal(): void
    {
        $result = ($this->tool())(term: 'Gravel Tyre', limit: 8);

        self::assertSame($result['total'], $result['matched']);
        self::assertFalse($result['more'], 'the window was not saturated, so this is the whole answer');
    }

    /**
     * `more` is the honest half of `matched`: when the candidate window filled up, `matched` is a
     * floor rather than a count (spec decision T4).
     *
     * At `limit: 1` the candidate window is `MIN_CANDIDATES` (20), and the fixture holds 17 sellable
     * units — so it cannot saturate, and `more` must be false rather than defaulting to true.
     */
    public function testMoreIsFalseWhenTheCandidateWindowWasNotFilled(): void
    {
        self::assertFalse(($this->tool())(term: 'bottle', limit: 1)['more']);
    }

    public function testAMissedSearchStillCarriesBothFields(): void
    {
        $result = ($this->tool())(term: 'zzzznotathing');

        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['matched']);
        self::assertFalse($result['more']);
        self::assertArrayHasKey('note', $result);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/SearchProductsToolWithheldTest.php`
Expected: FAIL — undefined array key `matched`.

- [ ] **Step 3: Capture the retrieved count**

In `src/Core/Tool/SearchProductsTool.php`, immediately after the second `RetrievalPass::run(...)`
block completes and before `$cards = $this->variantResolver->resolve(...)` (currently around line
292), add:

```php
        // Measured HERE, before variant resolution, because the gateway's limit applied to this set.
        // VariantResolver can replace a parent card with a variant card, so counting afterwards would
        // compare a post-resolution size against a pre-resolution bound.
        $windowSaturated = \count($cards) === $query->retrievalLimit();
```

- [ ] **Step 4: Add the fields to the reply**

Replace the `$result` assignment (currently line 337-341):

```php
        $result = [
            // id + name + options, never a figure — see ToolProductSummary for why bare ids made
            // variant identification cost one tool call per candidate.
            'products' => ToolProductSummary::of($returned),
            'total' => \count($returned),
        ];
```

with:

```php
        $result = [
            // id + name + options, never a figure — see ToolProductSummary for why bare ids made
            // variant identification cost one tool call per candidate.
            'products' => ToolProductSummary::of($returned),
            // `total` deliberately keeps meaning "how many are in products" (T3). The model has
            // learned it; redefining a number in place is how something else quietly breaks.
            'total' => \count($returned),
            // What the search actually found, which is the number `total` was being read as. Excludes
            // superseded parents: RedundantParentFilter removed them because their own variants are
            // present, and counting one back in would report a product twice.
            'matched' => \count($survivors),
            // `matched` is a floor, not a census, whenever the candidate window filled up (T4).
            'more' => $windowSaturated,
        ];
```

- [ ] **Step 5: Extend the return contract**

Replace the `@return` block (currently line 147-152):

```php
     * @return array{
     *     products: list<array{id: string, name: string, options: array<string, string>}>,
     *     total: int,
     *     note?: string,
     * }
```

with:

```php
     * @return array{
     *     products: list<array{id: string, name: string, options: array<string, string>}>,
     *     total: int,
     *     matched: int,
     *     more: bool,
     *     families?: list<array{
     *         name: string,
     *         shown: int,
     *         variants: int,
     *         options: array<string, list<string>>,
     *         options_truncated?: bool,
     *     }>,
     *     note?: string,
     * }
```

`families` is declared here in Task 2 even though Task 3 populates it, so the contract is written once
and the analyzer never sees a shape that disagrees with itself between commits.

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Core/Tool/SearchProductsToolWithheldTest.php`
Expected: PASS (4 tests).

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS. Existing tool tests read `products` and `total` and must be untouched by this — if any
fails, the additive promise (T3) is broken and the cause is in Step 4, not in the test.

- [ ] **Step 7: Commit**

```bash
composer run format
composer run quality
git add src/Core/Tool/SearchProductsTool.php tests/Core/Tool/SearchProductsToolWithheldTest.php
git commit -m "feat(tool): report how many products matched, not just how many were returned"
```

---

### Task 3: `families` in the reply

**Files:**
- Modify: `src/Core/Tool/SearchProductsTool.php` — the `$result` array from Task 2
- Modify: `tests/Core/Tool/SearchProductsToolWithheldTest.php`

**Interfaces:**
- Consumes: `TruncatedFamilies::of()` (Task 1), `matched`/`more` (Task 2).
- Produces: the `families` key, present only when a family was truncated.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Core/Tool/SearchProductsToolWithheldTest.php`:

```php
    /**
     * The failure this whole change exists for, in miniature.
     *
     * Two of four Gravel Tyre variants come back. `Tan` and `650x47` are only on the two that did
     * not — and the model must still be able to ask for them.
     */
    public function testATruncatedFamilyDisclosesTheOptionsOfTheVariantsItWithheld(): void
    {
        $result = ($this->tool())(term: 'Gravel Tyre', limit: 2);

        self::assertArrayHasKey('families', $result);
        self::assertCount(1, $result['families']);

        $family = $result['families'][0];
        self::assertSame('Gravel Tyre 40c', $family['name']);
        self::assertSame(2, $family['shown']);
        self::assertSame(4, $family['variants']);
        self::assertContains('Tan', $family['options']['Colour']);
        self::assertContains('650x47', $family['options']['Size']);
    }

    /** No truncation, no key. An empty families array is noise the model pays tokens to read. */
    public function testAnUntruncatedSearchOmitsTheFamiliesKeyEntirely(): void
    {
        self::assertArrayNotHasKey('families', ($this->tool())(term: 'Gravel Tyre', limit: 8));
    }

    /**
     * Spec decision T6 at the level that ships: whatever `families` contains, the reply the model
     * receives carries no figure. Asserted on the encoded reply because that is what crosses the
     * boundary.
     */
    public function testTheRepliesNewFieldsNeverCarryAFigure(): void
    {
        $result = ($this->tool())(term: 'Gravel Tyre', limit: 2);
        unset($result['products'], $result['note']);

        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);

        foreach (['price', 'stock', 'deliveryTime', 'url', 'EUR'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, \sprintf('%s leaked', $forbidden));
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Core/Tool/SearchProductsToolWithheldTest.php`
Expected: FAIL — `families` missing from the reply.

- [ ] **Step 3: Populate the key**

In `src/Core/Tool/SearchProductsTool.php`, directly after the `$result` array from Task 2 and before
the existing `if ($returned === [])` note block, add:

```php
        // Only when there is something to disclose. A family returned whole is already fully
        // described by `products`, and an empty array is context the model pays to read.
        $families = TruncatedFamilies::of($survivors, $returned);

        if ($families !== []) {
            $result['families'] = $families;
        }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Core/Tool/SearchProductsToolWithheldTest.php`
Expected: PASS (7 tests).

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS.

Run: `composer run quality`
Expected: exit 0.

- [ ] **Step 5: See the reply the model will actually get**

```bash
php -r '
require "vendor/autoload.php";
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\{FactRenderer, VariantResolver};
use Swag\AssistantStarterKit\Core\Policy\{AssistantConfig, BlocklistFilter};
use Swag\AssistantStarterKit\Core\Retrieval\{FacetProbe, QueryBuilder};
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
$g = FixtureCommerceGateway::fromFile("tests/Fixtures/catalog.json");
$t = new TraceRecorder();
$tool = new SearchProductsTool($g, new FacetProbe($g,$t), new QueryBuilder(), new VariantResolver($g,$t),
    new BlocklistFilter(), new FactRenderer($t), $t, new AssistantConfig());
echo json_encode($tool(term: "Gravel Tyre", limit: 2), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";'
```

Expected: `matched: 4`, `more: false`, and a `families` entry listing `Tan` and `650x47`. **Read it as
the model would** — if the shape is confusing to you it will be confusing to the model, and that is a
finding for Task 4 rather than something to leave.

- [ ] **Step 6: Commit**

```bash
composer run format
git add src/Core/Tool/SearchProductsTool.php tests/Core/Tool/SearchProductsToolWithheldTest.php
git commit -m "feat(tool): disclose the option values of a family the search truncated"
```

---

### Task 4: Does the model use it?

**Files:**
- Create: `docs/superpowers/reports/2026-08-25-tool-reply-withheld-outcome.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the answer to spec decision T7, which decides whether this design was right.

This task has no unit test. Its deliverable is a measurement against a live model, and T7 named the
falsifier in advance: **`scale_family_beyond_window` green, or the design is wrong.**

- [ ] **Step 1: Run the four scale journeys**

```bash
ASSISTANT_EVAL_CATALOG=large timeout 2400 vendor/bin/phpunit --group eval \
  --filter 'scale_' --testdox 2>&1 | tee /tmp/withheld-scale.txt
```

Expected: ~5 minutes and real model spend. Record every journey and archetype. The baseline to compare
against is the addendum in `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`:
`scale_family_beyond_window` 0/3 on both archetypes, `scale_deep_duplicate` 0/3 both,
`scale_broad_term` and `scale_option_beyond_facet_limit` passing.

- [ ] **Step 2: Run the fifteen existing journeys**

```bash
timeout 3000 vendor/bin/phpunit --group eval --filter '/^(?!.*scale_).*$/' --testdox 2>&1 \
  | tee /tmp/withheld-existing.txt
```

**Without** `ASSISTANT_EVAL_CATALOG`, deliberately: these fifteen are the regression check on the
small catalogue, and the reply shape is model input. `last_search_wins` and `plural_finds_singular`
are the likeliest to be surprised by a new field.

- [ ] **Step 3: Write the report**

Create `docs/superpowers/reports/2026-08-25-tool-reply-withheld-outcome.md`, with every cell a
measured value:

```markdown
# The Reply That States What It Withheld — Outcome

**Date:** <the day it ran>
**Spec:** `docs/superpowers/specs/2026-08-25-tool-reply-states-what-it-withheld-design.md`
**Baseline:** the addendum in `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`

## T7: did `scale_family_beyond_window` go green?

| Journey | Archetype | Before | After |
|---|---|---|---|
| scale_family_beyond_window | expert | FAIL 0/3 | |
| scale_family_beyond_window | beginner | FAIL 0/3 | |
| scale_deep_duplicate | expert | FAIL 0/3 | |
| scale_deep_duplicate | beginner | FAIL 0/3 | |
| scale_broad_term | expert | PASS | |
| scale_broad_term | beginner | PASS | |
| scale_option_beyond_facet_limit | both | PASS | |

## The fifteen existing journeys, small catalogue

| Journey | Result | Failing assertion |
|---|---|---|

## What the model did with the new fields

Whether it re-searched with an option from `families`, taken from the trace's `query.build` and
`retrieve` events across the runs. If it did not, say so plainly — that is the finding, not a
disappointment to be worded around.

## Verdict on T7

One of: the criterion was met and the design holds; or it was not met, in which case the recommended
next step is the alternative T2 rejected — the server resolving a named option against the family
without a second tool call.

## What this does not say

Nothing about a real DAL catalogue: these runs use the generated fixture and `FixtureTermMatcher`,
which has no keyword index and no ranking.
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/reports/2026-08-25-tool-reply-withheld-outcome.md
git commit -m "docs: whether the model uses a reply that admits what it withheld"
```

---

## Gate

**T7 decides what happens next, and the answer is allowed to be "no".** If
`scale_family_beyond_window` stays red, this design's premise — that telling the model the values
exist is enough — is falsified, and the next plan is the server-side resolution T2 rejected. Do not
respond to a red run by adding prompt instructions until the trace shows the model ignoring
`families`; that would be treating a measurement as a tuning problem.

**No constant is tuned here** and the vocabulary budget is untouched. If T7 passes, the vocabulary
block never needed to carry a family's options, and phase A's Finding 2 is closed without touching
`CatalogVocabularyBudget` at all.

## Spec coverage

| Decision | Where |
|---|---|
| T1 reply self-sufficient about options | Task 1 (`TruncatedFamilies`), Task 3 (wired in) |
| T2 server reports, model re-asks | Task 3 — nothing resolves the shopper's intent; the *Gate* records the alternative |
| T3 additive only, `total` unchanged | Task 2 Step 4's comment, `testMatchedReportsWhatSurvivedRetrievalNotWhatWasReturned` asserts `total` still equals `count($products)` |
| T4 `matched` is a floor, `more` says so | Task 2 Steps 3-4, `testMoreIsFalseWhenTheCandidateWindowWasNotFilled` |
| T5 option list capped at 50 | Task 1, `MAX_OPTION_VALUES` and `testTheOptionValueListIsCappedAndSaysSo` |
| T6 no figure in the reply | Task 1 `testNoFigureEverReachesTheSummary`, Task 3 `testTheRepliesNewFieldsNeverCarryAFigure` |
| T7 success is the journey going green | Task 4, and the *Gate* |
| `matched` excludes superseded parents | Task 2 Step 4's comment; the spec's *Where the numbers come from* |
| `more` measured before variant resolution | Task 2 Step 3 |

## Known risks

1. **`TruncatedFamilies` lands near the complexity ceiling.** Four methods with nested loops; the class threshold is 10 summed. If lint fires, split the option collection into its own class — do not reach for a pragma, and do not merge methods to reduce the count (`too-many-methods` and `cyclomatic-complexity` pull in opposite directions).
2. **The fixture's families are small.** Four variants is enough to prove truncation deterministically but not to prove the shape scales; the generated catalogue's thirty-variant family in Task 4 is what tests that.
3. **`more` can be false on a saturated window in one edge case:** if the blocklist removed cards, `count($cards)` at Step 3 is measured before removal, so saturation still reflects what the gateway returned. That is the intended reading — it describes the retrieval bound, not the post-filter set — but it means `matched` can be smaller than the window while `more` is true. Correct, and worth not "fixing".
4. **A new reply field changes model behaviour in ways no unit test predicts.** That is exactly what Task 4 measures, and why Step 2 re-runs the fifteen existing journeys rather than assuming additive means safe.
