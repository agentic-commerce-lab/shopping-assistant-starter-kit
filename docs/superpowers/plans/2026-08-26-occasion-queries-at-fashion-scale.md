# Occasion Queries at Fashion Scale — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the assistant answer "what to wear to a wedding" in a 15,000-product / 1,000-category
fashion shop — by giving it a view of the shop's own category tree, and one bounded clarifying
question it asks only when the tree proves the answer would otherwise be arbitrary.

**Architecture:** A new optional `CategoryTreeReader` capability interface sits *beside*
`CommerceGatewayInterface` (never inside it), implemented by both the DAL and fixture gateways. A new
`browse_categories` tool exposes bounded, figure-free nodes to the model, and `search_products` gains
a never-relaxed `category` argument so the model can retrieve from what it found. The elicitation
behaviour lives in the new tool's own description, so it appears exactly when the tool does.

**Tech Stack:** PHP 8.3+, Shopware 6.7, Symfony AI (`#[AsTool]` reflection-derived schemas), PHPUnit,
mago (format/lint/analyze).

**Spec:** `docs/superpowers/specs/2026-08-26-occasion-queries-at-fashion-scale-design.md`

## Global Constraints

- Every new PHP file: `declare(strict_types=1)`, `final` class, `namespace Swag\AssistantStarterKit\…`.
- `composer run quality` must exit 0. The commit hook already runs mago format + lint on staged files.
- mago gates that will bite: `cyclomatic-complexity` threshold **10, summed across a class's methods**;
  `excessive-parameter-list` threshold **5**; `excessive-nesting` threshold **4**. Only `error` level
  fails the build. Carve-outs use `// @mago-expect lint:<rule>` **with a reason comment**, as every
  existing one does.
- `php scripts/check_file_length.php src` caps files under `src/` at **400 physical lines**.
- **mago's `[source] paths` is `["src", "tests"]`** — the analyzer and linter cover test code too. A
  `json_decode` result is `mixed` there as everywhere: annotate with an imported `@phpstan-type`, the
  way `LargeCatalogGeneratorTest` does, rather than reaching for a suppression.
- `composer run quality:dupes` (jscpd) scans **`src` only** — `.jscpd.json` ignores `**/tests/**`. So it will not catch duplication in the fixture code; Task 2's extraction is justified on drift, not on the gate.
- Fast suite: `vendor/bin/phpunit --exclude-group eval`. Eval suite: `vendor/bin/phpunit --group eval`,
  needs `ASSISTANT_LLM_BASE_URL`, `ASSISTANT_LLM_API_KEY`, `ASSISTANT_LLM_MODEL`.
- **Never add a method to `CommerceGatewayInterface`.** It is `@api`; `BatchProductLookup` and
  `FamilyVariantLookup` are the pattern for optional capabilities.
- **No figure may reach the model.** No price, stock, delivery time or URL in any tool reply. Asserted
  against the encoded reply, not the class — see `TruncatedFamiliesTest::testNoFigureEverReachesTheSummary`.
- Generated catalogues are build outputs: they go to `var/` (gitignored). Only generators are committed.
- Conventional commits, one per task step group as written below.

## Two corrections to the spec, made while planning

Both are recorded here rather than silently implemented.

**1. The prompt rule moves from the system prompt into the tool description (spec §5).** §5 says the
rule is "placed with the rules, omitted entirely when the tool is absent". Omitting it requires the
prompt to know whether the tool exists, and the only ways to tell it are a fourth parameter on
`PromptProviderInterface::system()` — which is `@api`, and adding even an optional parameter makes
every third-party provider a fatal signature error — or a new `Bundle` field the runner threads
through, which has the same problem one layer down.

`SearchProductsTool`'s own `#[AsTool]` description already carries multi-paragraph behavioural
instruction ("search for the thing you are actually answering about last"). A tool description is
present exactly when its tool is, needs no interface change, and follows the strongest precedent in
the tree. So the occasion/elicitation rule goes there.

**The one part that cannot move is O9** — the clarification that general knowledge about an occasion
is permitted as *reasoning* while every product named must come from a tool. That contradicts a line
in `SystemPrompt::RULES`, and a tool description must never be able to override RULES. It is also
correct whether or not the category tool exists. So it goes into RULES, unconditionally (Task 9).

**2. Three assertion classes cover the spec's four assertion rows.** "At most one question" and "no
question asked" are the same check with a different bound, so `questions_at_most` with `max: 0`
covers the negative control. Spec §Evals says "four new assertions"; it is four rows, three classes.

## File Structure

**Part 1 — the gate. No production code.**

| File | Responsibility |
|---|---|
| `tests/Fixtures/Fashion/FashionCatalogGenerator.php` | Seeded generation of ~15,000 units across ~1,028 category nodes. Owns volume and the tree |
| `tests/Fixtures/Fashion/FashionTrapProducts.php` | The four named traps, no seeded state shared with the generator |
| `tests/Fixtures/GeneratedCatalogue.php` | Write-to-`var/`-with-a-content-stamp mechanics, extracted from `LargeCatalogFile` so the staleness logic has one home |
| `tests/Fixtures/Fashion/FashionCatalogFile.php` | Where the fashion catalogue lives, and generating it when stale |
| `tests/Fixtures/EvalCatalogue.php` | Resolves `ASSISTANT_EVAL_CATALOG` to one of three paths |
| `src/Eval/JourneyCatalogue.php` | A journey's declared catalogue requirement |
| `src/Command/SeedFashionCatalogCommand.php` | Dev-only: the same catalogue through the DAL |

**Part 2 — the capability.**

| File | Responsibility |
|---|---|
| `src/Core/Commerce/CategoryTreeReader.php` | The optional capability interface |
| `src/Core/Commerce/Dto/CategoryNode.php` | One node, figure-free |
| `src/Core/Commerce/Fixture/FixtureCategoryTree.php` | Nodes from `ProductCard::categoryPath` |
| `src/Core/Commerce/Dal/DalCategoryTreeReader.php` | Nodes from the sales-channel category repository |
| `src/Core/Tool/BrowseCategoriesTool.php` | The model-facing tool, and where the elicitation rule lives |
| `src/Core/Tool/Factory/BrowseCategoriesToolFactory.php` | Registers it only when the gateway can read categories |
| `src/Eval/Assertion/QuestionsAtMost.php` | The behaviour gate, both directions |
| `src/Eval/Assertion/QuestionNamesAReturnedCategory.php` | The question is grounded in what the tool returned |
| `src/Eval/Assertion/RenderedIdsFromEach.php` | O17: the cards span both branches |

**Modified:** `src/Core/Commerce/Dto/ProductQuery.php`, `src/Core/Commerce/Fixture/FixtureCategoryFilter.php`,
`src/Core/Commerce/Dal/DalCriteriaBuilder.php`, `src/Core/Commerce/FixtureCommerceGateway.php`,
`src/Core/Commerce/Dal/DalCommerceGateway.php`, `src/Core/Tool/SearchProductsTool.php`,
`src/Core/Prompt/SystemPrompt.php`, `src/Core/Agent/AssistantAgentFactory.php`,
`src/Eval/Assertion/AssertionRegistry.php`, `src/Eval/JourneyFileParser.php`, `src/Eval/Journey.php`,
`tests/Eval/JourneyEvalTest.php`, `tests/Fixtures/Large/LargeCatalogFile.php`,
`src/Resources/config/services.xml`.

---

# Part 1 — The gate

O14: Task 4 reports before Task 5 is started. **A clarification O14 needs:** "changes nothing under
`src/`" was written about production code. Tasks 2 and 3 do touch `src/Eval/` and `src/Command/` —
the eval harness and a dev-only console command. Neither is on the request path, and neither can
change what the assistant answers. Nothing under `src/Core/` is touched before the gate.

### Task 1: The fashion catalogue generator

**Files:**
- Create: `tests/Fixtures/Fashion/FashionCatalogGenerator.php`
- Create: `tests/Fixtures/Fashion/FashionTrapProducts.php`
- Create: `tests/Fixtures/Fashion/FashionCatalogQuery.php`
- Test: `tests/Fixtures/Fashion/FashionCatalogGeneratorTest.php`
- Test: `tests/Fixtures/Fashion/FashionTrapPresenceTest.php`

**Interfaces:**
- Consumes: `tests/Fixtures/catalog.json` (the twelve `fx-*` products), read verbatim.
- Produces:
  - `FashionCatalogGenerator::__construct(string $smallCatalogPath, int $seed = 20_260_826)`
  - `FashionCatalogGenerator::build(): array{products: list<FashionProduct>}` — `build()`, not
    `toArray()`: `LargeCatalogGenerator` names it that and `LargeCatalogQuery` calls it that
  - `FashionCatalogGenerator::toJson(): string`
  - `FashionCatalogGenerator::CATEGORY_NODES` (int), `::GENERATED_PARENTS` (int), `::SELLABLE_UNITS` (int)
  - `FashionTrapProducts::all(): list<FashionProduct>`
  - `FashionCatalogQuery::built()`, `::generator()`, `::smallCatalogPath()`, `::find()`, `::require()`,
    `::sellableUnits()`, `::categoryNodes()` — the shared lookups both fashion test classes need,
    mirroring `Large\LargeCatalogQuery`, which exists for exactly this reason
  - `FashionTrapProducts::OCCASION_DRESS_PREFIX = 'fw-occ-dress-'`
  - `FashionTrapProducts::OCCASION_SUIT_PREFIX = 'fw-occ-suit-'`
  - `FashionTrapProducts::FALSE_FRIEND_ID = 'fw-false-friend'`
  - `FashionTrapProducts::YOGA_PREFIX = 'fw-yoga-'`

The `FashionProduct` shape is the one `FixtureIndex::fromDecoded()` already reads — id, name,
description, price, stock, url, categoryPath, properties, variants — declared once as a
`@phpstan-type` on the generator, exactly as `LargeCatalogGenerator` declares `LargeProduct`.

- [ ] **Step 1: Write the failing test for the counts and the tree**

`tests/Fixtures/Fashion/FashionCatalogGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use PHPUnit\Framework\TestCase;

/**
 * The generator is the reviewable artefact (spec O11), so its output is asserted rather than
 * inspected. Every number here is the arithmetic documented on the constant it checks: a generator
 * that silently produces half a catalogue would otherwise make a whole measurement wrong in a way
 * that looks like a finding about the product.
 */
final class FashionCatalogGeneratorTest extends TestCase
{
    public function testTheTwelveRealProductsSurviveVerbatim(): void
    {
        $small = json_decode(
            (string) file_get_contents(__DIR__ . '/../catalog.json'),
            associative: true,
            depth: 512,
            flags: \JSON_THROW_ON_ERROR,
        );

        $generated = FashionCatalogQuery::built()['products'];

        foreach ($small['products'] as $index => $original) {
            self::assertSame($original['id'], $generated[$index]['id']);
            self::assertSame($original['name'], $generated[$index]['name']);
            self::assertSame($original['price'], $generated[$index]['price']);
            self::assertSame($original['stock'], $generated[$index]['stock']);
        }
    }

    public function testItProducesTheDocumentedNumberOfSellableUnits(): void
    {
        $units = 0;

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            $units += $product['variants'] === [] ? 1 : \count($product['variants']);
        }

        self::assertSame(FashionCatalogGenerator::SELLABLE_UNITS, $units);
    }

    public function testItProducesAtLeastAThousandCategoryNodes(): void
    {
        $nodes = [];

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            $path = [];

            foreach ($product['categoryPath'] as $segment) {
                $path[] = $segment;
                $nodes[implode('/', $path)] = true;
            }
        }

        self::assertGreaterThanOrEqual(1_000, \count($nodes));
        self::assertSame(FashionCatalogGenerator::CATEGORY_NODES, \count($nodes));
    }

    public function testItIsDeterministicForOneSeed(): void
    {
        self::assertSame(FashionCatalogQuery::generator()->toJson(), FashionCatalogQuery::generator()->toJson());
    }

    /** @return array{products: list<array<string, mixed>>} */
    private function catalogue(): array
    {
        return $this->generator()->toArray();
    }

    private function generator(): FashionCatalogGenerator
    {
        return new FashionCatalogGenerator(__DIR__ . '/../catalog.json');
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/phpunit tests/Fixtures/Fashion/FashionCatalogGeneratorTest.php`
Expected: FAIL — `Class "…\Fashion\FashionCatalogGenerator" not found`.

- [ ] **Step 3: Write the generator**

`tests/Fixtures/Fashion/FashionCatalogGenerator.php`. Copy `LargeCatalogGenerator`'s seeded-LCG
approach verbatim — same glibc constants, same reason (deterministic across platforms and PHP
versions, four lines, no extension). Do **not** extend that class: the two catalogues answer different
questions and a shared base would couple their trap sets.

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * A fashion catalogue at the scale of a real shop: ~15,000 sellable units across ~1,028 category
 * nodes.
 *
 * **What is new here, and why it is a second generator rather than a parameter on the first.**
 * `LargeCatalogGenerator` crosses the product-side constants — candidate windows, variant families,
 * facet value limits. Nothing in this kit has ever met a category TREE, and the tree is what this
 * one is for: the word a shopper says ("wedding") is not in the catalogue at all, and the only route
 * from that word to a product runs through category names the shopper never typed.
 *
 * **Seeded arithmetic, not `random_int()`** — same reasoning as `LargeCatalogGenerator`: a fixture
 * that differs between runs turns a red eval into a coin toss.
 *
 * **The twelve real products are copied verbatim** (spec O10), first and in order, so all fifteen
 * existing journeys run against this catalogue unchanged. They land under `Sport > Cycling`, which is
 * a plausible department for a fashion shop to have and keeps them out of every trap's subtree.
 *
 * **The word "wedding" appears in exactly one product in this catalogue, and it is not wearable.**
 * That is trap `fw-occasion-word` and trap `fw-false-friend` seen from either side; see
 * {@see FashionTrapProducts}. `FashionTrapPresenceTest` asserts it over the whole encoded output,
 * because it is the premise the entire measurement rests on and a stray generated name would void it.
 *
 * @phpstan-type FashionVariant array{id: string, options: array<string, string>, price: float, stock: int}
 * @phpstan-type FashionProduct array{
 *     id: string,
 *     name: string,
 *     description: string|null,
 *     price: float,
 *     stock: int,
 *     url: string,
 *     categoryPath: list<string>,
 *     properties: array<string, list<string>>,
 *     variants: list<FashionVariant>,
 * }
 * @phpstan-type FashionCatalogue array{products: list<FashionProduct>}
 */
final class FashionCatalogGenerator
{
    /** Top-level departments that carry the garment tree. */
    public const DEPARTMENTS = ['Women', 'Men', 'Kids'];

    /** Garment types under each department. */
    public const GARMENT_TYPES = [
        'Dresses', 'Tops', 'Knitwear', 'Trousers', 'Skirts', 'Suits & Tailoring',
        'Outerwear', 'Shoes', 'Bags & Accessories', 'Occasion & Party', 'Swimwear', 'Denim',
        'Loungewear', 'Activewear',
    ];

    /** Cuts, used to name subtypes under each garment type. */
    public const CUTS = [
        'Maxi', 'Midi', 'Mini', 'Wrap', 'Shirt', 'Slip', 'Bodycon', 'A-Line', 'Shift',
        'Sheath', 'Tea', 'Smock', 'Tiered', 'Cami', 'Halter', 'Pinafore', 'Knitted',
        'Cropped', 'Oversized', 'Tailored', 'Relaxed', 'Slim',
    ];

    /**
     * 3 departments × 14 garment types × 22 cuts = 924 leaves, plus 42 garment-type nodes and 3
     * department nodes = 969. Plus the Brand branch (1 + 40), Season (1 + 4), Occasion (1 + 10) and
     * Sport (1 + 1) = 59. Total 1,028.
     *
     * Asserted rather than described: a tree the generator quietly halves is a measurement about a
     * catalogue nobody has.
     */
    public const CATEGORY_NODES = 1_028;

    /**
     * How many generated parent products, and the arithmetic for the unit count.
     *
     * 3,600 parents, of which every fifth has no variants (720 parents, 720 units) and the rest have
     * five sizes (2,880 × 5 = 14,400 units) = 15,120. Plus the seventeen units of the twelve real
     * products and the trap products' own units — see {@see self::SELLABLE_UNITS}.
     *
     * Keeping a fifth variant-free is deliberate, and the same reason `LargeCatalogGenerator` does:
     * `StockSource::Product` and `StockSource::Variant` must both occur at scale.
     */
    public const GENERATED_PARENTS = 3_600;

    private const FILLER_SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    /**
     * 15,120 generated + 17 real + the traps' units. Fill this in from the first red run of
     * `testItProducesTheDocumentedNumberOfSellableUnits` — the arithmetic is deterministic, so the
     * measured number IS the correct constant, and writing a guess here first is how a generator
     * bug becomes a "finding".
     */
    public const SELLABLE_UNITS = 15_120 + 17 + 46;

    private int $state;

    public function __construct(
        private readonly string $smallCatalogPath,
        int $seed = 20_260_826,
    ) {
        $this->state = $seed;
    }

    /** @return FashionCatalogue */
    public function toArray(): array
    {
        return ['products' => [
            ...$this->realProducts(),
            ...FashionTrapProducts::all(),
            ...$this->generated(),
        ]];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
    }

    /**
     * The twelve real products, re-pathed under `Sport > Cycling` and otherwise untouched.
     *
     * Re-pathing is the ONE change made to them, and it is safe: no existing journey asserts a
     * category path. `CategoryConstraintTest` reads `categoryPath[0]` from whatever the small
     * catalogue holds, and runs against the small catalogue, not this one.
     *
     * @return list<FashionProduct>
     */
    private function realProducts(): array { /* decode $smallCatalogPath, map categoryPath to ['Sport', 'Cycling'] */ }

    /** @return list<FashionProduct> */
    private function generated(): array { /* GENERATED_PARENTS products, see below */ }

    /** glibc's LCG constants — see the class docblock. */
    private function next(int $bound): int
    {
        $this->state = ($this->state * 1_103_515_245 + 12_345) & 0x7FFFFFFF;

        return $this->state % $bound;
    }
}
```

The `generated()` loop, stated precisely so it can be written without re-deriving it:

- For `$i` in `0 .. GENERATED_PARENTS - 1`: pick `$department = DEPARTMENTS[$this->next(3)]`,
  `$type = GARMENT_TYPES[$this->next(14)]`, `$cut = CUTS[$this->next(22)]`.
- `categoryPath` is `[$department, $type, $cut . ' ' . $type]`.
- `id` is `sprintf('fwg-%04d', $i)`; `name` is `$cut . ' ' . $this->singular($type)` plus a seeded
  two-digit suffix so names repeat realistically without colliding as ids; `url` is `/detail/` . id.
- `properties` carries `Colour` (one of 24 seeded values), `Material` (one of 12) and `Pattern`
  (one of 8) — fashion's real property groups, which is also what makes the vocabulary block's
  behaviour at this scale worth measuring.
- Every fifth `$i` (`$i % 5 === 0`) gets `variants: []`; the rest get five variants, one per
  `FILLER_SIZES` entry, id `<parentId>-<lowercased size>`, price the parent's, stock `$this->next(9)`.
- **The name must never contain "wedding".** `singular()` and the word lists above contain no
  occasion words at all, which is what makes that true by construction rather than by filtering.

`Brand`, `Season`, `Occasion` and `Sport` nodes are reached by re-pathing: every 90th generated
product also gets a second path — no. **One path per product**, and the extra 59 nodes come from the
trap products and from 59 dedicated generated products whose `categoryPath` is
`['Brand', $brandName]`, `['Season', $season]` or `['Occasion', $occasion]`. Take them from the front
of the generated loop (`$i < 59`) so the count is exact and the arithmetic above still holds.

**The `Occasion` branch must not contain the word "wedding".** Its ten values are `Party`, `Evening`,
`Cocktail`, `Black Tie`, `Garden Party`, `Christening`, `Graduation`, `Prom`, `Race Day`, `Festival`.
That omission is the trap: a real shop's occasion taxonomy plausibly has no node named after the
occasion this shopper named, and an assistant that only pattern-matches words fails here.

- [ ] **Step 4: Write the trap products**

`tests/Fixtures/Fashion/FashionTrapProducts.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * The four traps of spec §Traps, each with an id a failing assertion can name. No seeded state: these
 * are literals, so a change to {@see FashionCatalogGenerator}'s arithmetic cannot move them.
 *
 * @phpstan-import-type FashionProduct from FashionCatalogGenerator
 */
final class FashionTrapProducts
{
    /** `fw-gender-split`, the women's side: 6 occasion dresses, `Women > Occasion & Party > Occasion Dresses`. */
    public const OCCASION_DRESS_PREFIX = 'fw-occ-dress-';

    /** `fw-gender-split`, the men's side: 6 occasion suits, `Men > Suits & Tailoring > Occasion Suits`. */
    public const OCCASION_SUIT_PREFIX = 'fw-occ-suit-';

    /**
     * `fw-false-friend`. The ONLY product in this catalogue whose name contains "wedding", and it is
     * not a garment: a cake-topper charm in `Gifts & Novelty`. A keyword search for the shopper's own
     * word returns exactly this and nothing else — which is worse than an empty result, because the
     * search technically succeeded.
     */
    public const FALSE_FRIEND_ID = 'fw-false-friend';

    /**
     * `fw-undivided`, the negative control. Yoga wear exists under `Women > Activewear > Yoga` and
     * NOWHERE else — no men's or kids' equivalent. So the answer to "what should I wear to a yoga
     * class" does not change with anything the shopper could tell us, and the correct behaviour is to
     * recommend without asking. Without this trap the suite can only prove the assistant asks, never
     * that it asks selectively (spec O13).
     */
    public const YOGA_PREFIX = 'fw-yoga-';

    private function __construct() {}

    /** @return list<FashionProduct> */
    public static function all(): array
    {
        return [...self::occasionDresses(), ...self::occasionSuits(), self::falseFriend(), ...self::yoga()];
    }
}
```

Each of the four private builders returns literal products in the shape above: six dresses
(`fw-occ-dress-1` … `-6`, each with a `Colour`/`Size` family of five variants), six suits
(`fw-occ-suit-1` … `-6`, likewise), the single false friend (no variants), and four yoga products
(`fw-yoga-1` … `-4`, five variants each). Names are garment names — "Silk Slip Occasion Dress",
"Three-Piece Occasion Suit" — and **none of them contains "wedding"**.

Unit arithmetic, which is where `SELLABLE_UNITS`'s `+ 46` comes from: 6 × 5 + 6 × 5 + 1 + 4 × 5 = 51.
Take the measured number from Step 6 rather than trusting this line — if 51 and 46 disagree, the
literal builders are the truth and the constant is wrong.

- [ ] **Step 5: Write the trap-presence test**

`tests/Fixtures/Fashion/FashionTrapPresenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the four traps are present and, for `fw-occasion-word`, that its premise holds over the
 * WHOLE encoded catalogue rather than over the trap file. A generated product name that happened to
 * contain "wedding" would quietly turn the central measurement into a different experiment.
 */
final class FashionTrapPresenceTest extends TestCase
{
    public function testOnlyOneProductInTheWholeCatalogueMentionsTheOccasion(): void
    {
        $mentions = [];

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            $haystack = strtolower($product['name'] . ' ' . ($product['description'] ?? '')
                . ' ' . implode(' ', $product['categoryPath'])
                . ' ' . implode(' ', array_merge(...array_values($product['properties']) ?: [[]])));

            if (str_contains($haystack, 'wedding')) {
                $mentions[] = $product['id'];
            }
        }

        self::assertSame([FashionTrapProducts::FALSE_FRIEND_ID], $mentions);
    }

    public function testTheGenderSplitIsDisjointAndBothSidesAreStocked(): void
    {
        $dresses = $this->idsMatching(FashionTrapProducts::OCCASION_DRESS_PREFIX);
        $suits = $this->idsMatching(FashionTrapProducts::OCCASION_SUIT_PREFIX);

        self::assertNotSame([], $dresses);
        self::assertNotSame([], $suits);
        self::assertSame([], array_intersect($dresses, $suits));

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            if (\in_array($product['id'], $dresses, strict: true)) {
                self::assertSame(['Women', 'Occasion & Party', 'Occasion Dresses'], $product['categoryPath']);
            }

            if (\in_array($product['id'], $suits, strict: true)) {
                self::assertSame(['Men', 'Suits & Tailoring', 'Occasion Suits'], $product['categoryPath']);
            }
        }
    }

    public function testYogaWearExistsUnderExactlyOneDepartment(): void
    {
        $departments = [];

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            if (\in_array('Yoga', $product['categoryPath'], strict: true)) {
                $departments[$product['categoryPath'][0]] = true;
            }
        }

        self::assertSame(['Women'], array_keys($departments));
    }

    /** @return list<string> */
    private function idsMatching(string $prefix): array
    {
        $ids = [];

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            if (str_starts_with($product['id'], $prefix)) {
                $ids[] = $product['id'];
            }
        }

        return $ids;
    }

    /** @return array{products: list<array<string, mixed>>} */
    private function catalogue(): array
    {
        return (new FashionCatalogGenerator(__DIR__ . '/../catalog.json'))->toArray();
    }
}
```

- [ ] **Step 6: Run both tests; take the real numbers**

Run: `vendor/bin/phpunit tests/Fixtures/Fashion/ --testdox`

`testItProducesTheDocumentedNumberOfSellableUnits` and `testItProducesAtLeastAThousandCategoryNodes`
will report the measured values. **Set `SELLABLE_UNITS` and `CATEGORY_NODES` to what was measured**,
and update the arithmetic comment above each so it derives the measured number. Do not adjust the
generator to hit a pre-chosen constant — the constants describe the generator, not the reverse.

Then re-run until green. `testOnlyOneProductInTheWholeCatalogueMentionsTheOccasion` must be green
without a filter step in the generator: if it is red, a word list contains an occasion word and the
word list is what changes.

- [ ] **Step 7: Commit**

```bash
git add tests/Fixtures/Fashion
git commit -m "test(fixture): a fashion catalogue with a thousand categories and four traps"
```

### Task 2: Which catalogue a journey runs against

Today `JourneyEvalTest::journeys()` globs every file under `tests/Journeys/` and runs all of them
against whichever catalogue `ASSISTANT_EVAL_CATALOG` selected. The four `scale_*` journeys carry
`REQUIRES ASSISTANT_EVAL_CATALOG=large` **in a comment**, which nothing enforces — so a default run
executes `scale_family_beyond_window` against a catalogue that has no thirty-variant family, and pays
for a red result that means nothing.

The fashion journeys make that unacceptable rather than untidy: "what to wear to a wedding" against
twelve cycling products is not a weak test, it is a different question. So the requirement becomes a
declared field and the runner skips what does not match.

**Files:**
- Create: `tests/Fixtures/GeneratedCatalogue.php`
- Create: `tests/Fixtures/Fashion/FashionCatalogFile.php`
- Create: `tests/Fixtures/EvalCatalogue.php`
- Create: `src/Eval/JourneyCatalogue.php`
- Modify: `tests/Fixtures/Large/LargeCatalogFile.php` (use the extracted writer; drop `chosen()`)
- Modify: `src/Eval/JourneyFileParser.php`, `src/Eval/Journey.php`
- Modify: `tests/Eval/JourneyEvalTest.php`
- Modify: the four `tests/Journeys/scale_*.php` (declare `catalog`)
- Test: `tests/Eval/JourneyCatalogueTest.php`, `tests/Fixtures/EvalCatalogueTest.php`

**Interfaces:**
- Consumes: `FashionCatalogGenerator::toJson()` from Task 1.
- Produces:
  - `GeneratedCatalogue::write(string $path, list<string> $stampSources, callable(): string $produce): void`
  - `GeneratedCatalogue::stampPath(string $path): string`
  - `FashionCatalogFile::path(): string` — absolute, generated when absent or stale
  - `FashionCatalogFile::fashionPath(): string` — the location, without generating
  - `EvalCatalogue::chosen(): string` — the path an eval run should use
  - `EvalCatalogue::chosenName(): string` — `'small'`, `'large'` or `'fashion'`
  - `JourneyCatalogue::parse(mixed $raw, string $journeyId): self`, `->requires(string $catalogueName): bool`,
    `->name(): string`
  - `Journey::$catalogue` (a `JourneyCatalogue`)

- [ ] **Step 1: Write the failing test for the journey field**

`tests/Eval/JourneyCatalogueTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\JourneyCatalogue;

/**
 * `any` is the default because every journey written before this field existed was written against
 * the small catalogue and re-verified against the large one — spec O10 keeps that true for the
 * fashion catalogue too. A journey that names a catalogue is making a claim it could not make
 * otherwise, and a typo in that claim must be loud (the same reason `AssertionRegistry`'s default arm
 * throws).
 */
final class JourneyCatalogueTest extends TestCase
{
    public function testAJourneyThatNamesNoCatalogueRunsAgainstAllOfThem(): void
    {
        $catalogue = JourneyCatalogue::parse(null, 'some_journey');

        self::assertSame('any', $catalogue->name());
        self::assertTrue($catalogue->requires('small'));
        self::assertTrue($catalogue->requires('large'));
        self::assertTrue($catalogue->requires('fashion'));
    }

    public function testAJourneyThatNamesOneRunsOnlyAgainstThatOne(): void
    {
        $catalogue = JourneyCatalogue::parse('fashion', 'some_journey');

        self::assertSame('fashion', $catalogue->name());
        self::assertTrue($catalogue->requires('fashion'));
        self::assertFalse($catalogue->requires('small'));
    }

    public function testAnUnknownCatalogueNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fashon/');

        JourneyCatalogue::parse('fashon', 'some_journey');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Eval/JourneyCatalogueTest.php`
Expected: FAIL — `Class "…\Eval\JourneyCatalogue" not found`.

- [ ] **Step 3: Write `JourneyCatalogue`**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Which catalogue a journey's expectations were written against.
 *
 * **Why this is not a comment.** The four `scale_*` journeys carried their requirement in a header
 * comment, and a default eval run therefore executed them against a twelve-product catalogue that has
 * none of the shapes they assert. The result was a red run that meant nothing, paid for at real-model
 * prices. A declared requirement is skipped instead.
 *
 * **`any` is the default, and it is the honest one.** Every journey written before this field existed
 * runs against all three catalogues by design — spec decision O10 keeps the twelve real products
 * verbatim in the generated catalogues precisely so those journeys stay meaningful there. Defaulting
 * to `small` would silently stop running fifteen journeys at scale.
 *
 * An unknown name throws, for the same reason {@see Assertion\AssertionRegistry} throws on an unknown
 * assertion: a typo must not become a skipped journey nobody notices.
 */
final readonly class JourneyCatalogue
{
    public const ANY = 'any';

    public const KNOWN = ['any', 'small', 'large', 'fashion'];

    private function __construct(private string $name) {}

    public static function parse(mixed $raw, string $journeyId): self
    {
        if ($raw === null) {
            return new self(self::ANY);
        }

        if (!\is_string($raw) || !\in_array($raw, self::KNOWN, strict: true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares unknown catalog "%s"; expected one of %s.',
                $journeyId,
                \is_string($raw) ? $raw : get_debug_type($raw),
                implode(', ', self::KNOWN),
            ));
        }

        return new self($raw);
    }

    public function requires(string $catalogueName): bool
    {
        return $this->name === self::ANY || $this->name === $catalogueName;
    }

    public function name(): string
    {
        return $this->name;
    }
}
```

- [ ] **Step 4: Wire it into the parser and the value object**

In `src/Eval/JourneyFileParser.php`, add to the returned array and to BOTH the `@phpstan-type` and
the `@return` shape (they are written out twice in that file today — keep them identical):

```php
'catalogue' => JourneyCatalogue::parse($data['catalog'] ?? null, $id),
```

The array key is `catalogue` and the journey-file key is `catalog`, matching
`ASSISTANT_EVAL_CATALOG`'s own spelling — journey files are read beside that environment variable,
and the value object is read beside the rest of this namespace's British spellings.

In `src/Eval/Journey.php`, add the constructor property last, after `$page`:

```php
        /**
         * Which catalogue this journey's expectations hold against. Defaults to every catalogue —
         * see {@see JourneyCatalogue}.
         */
        public JourneyCatalogue $catalogue,
```

`Journey::fromFile()` spreads the parser's array into the constructor by name, so no other change is
needed there. `Journey` already carries a `@mago-expect lint:excessive-parameter-list` with a reason;
extend that reason comment with "and the catalogue its expectations hold against".

- [ ] **Step 5: Run the eval-harness unit tests**

Run: `vendor/bin/phpunit tests/Eval --exclude-group eval`
Expected: PASS. `JourneyConfigTest` and `JourneyTest` construct journeys — if either constructs
`Journey` positionally it will now fail; fix by passing `catalogue: JourneyCatalogue::parse(null, 'x')`
rather than by reordering the constructor.

- [ ] **Step 6: Commit**

```bash
git add src/Eval/JourneyCatalogue.php src/Eval/JourneyFileParser.php src/Eval/Journey.php tests/Eval
git commit -m "test(eval): a journey declares which catalogue it was written against"
```

- [ ] **Step 7: Extract the generated-catalogue writer**

`LargeCatalogFile` owns three things: the env switch, the write-with-stamp mechanics, and the paths.
Only the middle one is worth sharing.

**The gate will NOT catch a copy here** — `.jscpd.json` scans `src` only and ignores `**/tests/**`, so
a second copy of `write()`/`sourceStamp()`/`readStamp()` would pass every check. Extract it anyway, on
the merit: the staleness rule is subtle (content-stamped, not mtime-keyed, because `git checkout`
rewrites mtimes) and a silently diverged second copy means one catalogue is stale while its report
names the new generator. That is the failure the original comment was written about.

Create `tests/Fixtures/GeneratedCatalogue.php` holding, verbatim from `LargeCatalogFile`, the
`write()`, `sourceStamp()`, `readStamp()` and `stampPath()` logic, parameterised:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures;

/**
 * Writes a generated catalogue to `var/` and keeps it current, for any generator.
 *
 * Extracted from `Large\LargeCatalogFile` when a second generated catalogue arrived. Every comment
 * below was written for that one and is unchanged, because the reasoning is unchanged.
 *
 * **Stale, not just absent.** Keying the cache on existence alone means a change to a generator
 * leaves last week's catalogue on disk, and the next eval run measures that one while the report names
 * the new generator — a wrong number that looks exactly like a right one.
 *
 * **Content, not mtime.** A `git checkout` rewrites mtimes without changing a byte.
 */
final class GeneratedCatalogue
{
    private function __construct() {}

    /**
     * @param list<string>   $stampSources files whose content decides whether `$path` is stale
     * @param callable(): string $produce  the generator's JSON, called only when a write is needed
     */
    public static function ensure(string $path, array $stampSources, callable $produce): string
    {
        $stamp = self::stampOf($stampSources);

        if (!is_file($path) || self::readStamp($path) !== $stamp) {
            self::write($path, $stamp, $produce);
        }

        return $path;
    }

    public static function stampPath(string $path): string
    {
        return $path . '.stamp';
    }

    // write(), stampOf(), readStamp() move here from LargeCatalogFile unchanged except for the
    // injected $produce and $stampSources. Keep the atomic temp-file + rename, and keep the three
    // writes sharing one guard — the cyclomatic budget reasoning in that comment still applies.
}
```

Then rewrite `LargeCatalogFile` to delegate: keep `path()`, `largePath()`, `smallPath()` and
`stampPath()` (the last as a pass-through, since a test drops it), **delete `chosen()` and the `ENV`
and `LARGE` constants** — `EvalCatalogue` owns the switch now.

- [ ] **Step 8: Write `FashionCatalogFile` and `EvalCatalogue`**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use Swag\AssistantStarterKit\Tests\Fixtures\GeneratedCatalogue;

/** Where the generated fashion catalogue lives, and generating it when it is absent or stale. */
final class FashionCatalogFile
{
    private function __construct() {}

    public static function path(): string
    {
        return GeneratedCatalogue::ensure(
            self::fashionPath(),
            [
                __DIR__ . '/FashionCatalogGenerator.php',
                __DIR__ . '/FashionTrapProducts.php',
                self::smallPath(),
            ],
            static fn(): string => (new FashionCatalogGenerator(self::smallPath()))->toJson(),
        );
    }

    public static function fashionPath(): string
    {
        return \dirname(__DIR__, levels: 3) . '/var/catalog-fashion.json';
    }

    private static function smallPath(): string
    {
        return \dirname(__DIR__, levels: 3) . '/tests/Fixtures/catalog.json';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures;

use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionCatalogFile;
use Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogFile;

/**
 * Resolves `ASSISTANT_EVAL_CATALOG` to the catalogue an eval run should use.
 *
 * **An unknown value falls back to small rather than failing** — inherited from `LargeCatalogFile`,
 * and the reasoning is unchanged: a typo in an environment variable is not worth a red suite, and the
 * expensive mistake is a run that silently used the fifteen-thousand-unit catalogue because someone
 * wrote `fashon`. {@see \Swag\AssistantStarterKit\Eval\JourneyCatalogue} is where a typo IS loud,
 * because there it is a claim rather than a selection.
 */
final class EvalCatalogue
{
    private const ENV = 'ASSISTANT_EVAL_CATALOG';

    public const SMALL = 'small';

    private function __construct() {}

    public static function chosenName(): string
    {
        $requested = strtolower(trim((string) getenv(self::ENV)));

        return match ($requested) {
            'large' => 'large',
            'fashion' => 'fashion',
            default => self::SMALL,
        };
    }

    public static function chosen(): string
    {
        return match (self::chosenName()) {
            'large' => LargeCatalogFile::path(),
            'fashion' => FashionCatalogFile::path(),
            default => LargeCatalogFile::smallPath(),
        };
    }
}
```

- [ ] **Step 9: Write the switch test**

`tests/Fixtures/EvalCatalogueTest.php` — asserts `chosenName()` for unset, `large`, `fashion`,
mixed case (`FASHION`), whitespace (`" fashion "`) and a typo (`fashon` → `small`); and that
`chosen()` returns a path ending in the expected filename for each. Set the variable with `putenv()`
and restore it in `tearDown()`. Do **not** call `chosen()` for `fashion` in this test — it would
generate a 7 MB catalogue as a side effect of a string assertion. Assert `FashionCatalogFile::fashionPath()`
instead, and let Task 4's first eval run be what generates the file.

- [ ] **Step 10: Make the eval test honour the declaration**

In `tests/Eval/JourneyEvalTest.php`, replace the `LargeCatalogFile` import and use with
`EvalCatalogue`, and skip before spending anything:

```php
        $journey = Journey::fromFile($path);
        $catalogue = EvalCatalogue::chosenName();

        if (!$journey->catalogue->requires($catalogue)) {
            self::markTestSkipped(\sprintf(
                'Journey "%s" is written against the %s catalogue; this run uses %s.',
                $journey->id,
                $journey->catalogue->name(),
                $catalogue,
            ));
        }
```

Place it immediately after `Journey::fromFile()` and **before** the `LlmSettings` construction, so a
skipped journey costs no model call. Then `$runner = new JourneyRunner($settings, EvalCatalogue::chosen());`.

- [ ] **Step 11: Declare `catalog` on the four scale journeys**

Add `'catalog' => 'large',` to each of `tests/Journeys/scale_broad_term.php`,
`scale_deep_duplicate.php`, `scale_family_beyond_window.php`, `scale_option_beyond_facet_limit.php`,
and delete the now-redundant `REQUIRES ASSISTANT_EVAL_CATALOG=large` line from each header comment —
the declaration is the requirement now, and two statements of one fact drift.

- [ ] **Step 12: Verify the skip works without spending money**

Run: `vendor/bin/phpunit --group eval --testdox` with no LLM credentials set.
Expected: every journey SKIPPED by `setUp()` (unchanged behaviour).

Run: `ASSISTANT_EVAL_CATALOG=fashion vendor/bin/phpunit tests/Eval --exclude-group eval`
Expected: PASS.

Then, with credentials set and `--filter` limited to one scale journey:
Run: `vendor/bin/phpunit --group eval --filter scale_broad_term`
Expected: SKIPPED, with the message naming `large` and `small`. That is the whole point of the task —
verify it before Task 4 relies on it.

- [ ] **Step 13: Run the full fast suite and commit**

Run: `composer run test && composer run quality`
Expected: both exit 0.

```bash
git add tests/Fixtures src/Eval tests/Eval tests/Journeys
git commit -m "test(eval): select the catalogue by name, and skip journeys written for another"
```

### Task 3: Seed the same catalogue into a real shop

**Files:**
- Create: `src/Command/SeedFashionCatalogCommand.php`
- Create: `src/Command/Seed/FashionCategoryTreeWriter.php`
- Create: `src/Command/Seed/FashionProductWriter.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Command/SeedFashionCatalogCommandTest.php`

**Interfaces:**
- Consumes: nothing from Tasks 1–2. **Deliberately:** `tests/` is not autoloaded in a running
  Shopware installation, so this command cannot use `FashionCatalogGenerator`. It owns the same word
  lists, and `FashionCategoryTreeWriter::DEPARTMENTS`/`GARMENT_TYPES`/`CUTS` must be kept identical to
  the generator's. `SeedFashionCatalogCommandTest` asserts that identity by comparing the two classes'
  constants, so a drift is a red test rather than two different experiments.
- Produces:
  - `swag:assistant:seed-fashion --products=15000 --sales-channel=<id> [--dry-run]`
  - `FashionCategoryTreeWriter::write(Context $context, string $rootId): array<string, string>` —
    category path string → created id
  - `FashionProductWriter::write(Context $context, array $categoryIds, int $products, string $salesChannelId): int` — units created

- [ ] **Step 1: Read how Shopware seeds products, before writing any payload**

Read `vendor/shopware/core/Framework/Demodata/Generator/ProductGenerator.php` and
`vendor/shopware/core/Framework/Demodata/Generator/CategoryGenerator.php`. They are the authoritative
minimal payloads for this Shopware version. Note in particular: which fields `product.repository`
requires (`id`, `productNumber`, `name`, `stock`, `taxId`, `price` with `currencyId`/`gross`/`net`/
`linked`, `visibilities`), how `taxId` and `currencyId` are obtained, and the batch size the generator
uses for `create()`.

**Do not guess a payload.** A create() that throws halfway leaves a partially seeded shop, and the
whole point of this command is that the shop it produces is describable.

- [ ] **Step 2: Write the failing test for the word-list identity**

`tests/Command/SeedFashionCatalogCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionCategoryTreeWriter;
use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionCatalogGenerator;

/**
 * The fixture generator and the DAL seeder must build the SAME tree, or spec decision O3's
 * "measure against both" compares two different shops and calls the difference a finding.
 *
 * They cannot share a class: `tests/` is not autoloaded inside a running Shopware installation. So
 * the duplication is deliberate and this test is the thing that makes it safe.
 */
final class SeedFashionCatalogCommandTest extends TestCase
{
    public function testTheSeederAndTheFixtureShareOneTaxonomy(): void
    {
        self::assertSame(FashionCatalogGenerator::DEPARTMENTS, FashionCategoryTreeWriter::DEPARTMENTS);
        self::assertSame(FashionCatalogGenerator::GARMENT_TYPES, FashionCategoryTreeWriter::GARMENT_TYPES);
        self::assertSame(FashionCatalogGenerator::CUTS, FashionCategoryTreeWriter::CUTS);
    }

    public function testTheSeederCarriesNoOccasionWordEither(): void
    {
        $words = [
            ...FashionCategoryTreeWriter::DEPARTMENTS,
            ...FashionCategoryTreeWriter::GARMENT_TYPES,
            ...FashionCategoryTreeWriter::CUTS,
            ...FashionCategoryTreeWriter::OCCASIONS,
        ];

        foreach ($words as $word) {
            self::assertStringNotContainsStringIgnoringCase('wedding', $word);
        }
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Command/SeedFashionCatalogCommandTest.php`
Expected: FAIL — `Class "…\Command\Seed\FashionCategoryTreeWriter" not found`.

- [ ] **Step 4: Write the two writers and the command**

`FashionCategoryTreeWriter` holds the four constants and creates the tree under the sales channel's
navigation category with `category.repository->create()`, in one payload per department (a nested
`children` array is one create call per department, which is how `CategoryGenerator` does it). It
returns the path→id map the product writer needs.

`FashionProductWriter` creates products in batches of 100 (`ProductGenerator`'s own batch size),
assigning each to one leaf category id from the map, with the same seeded arithmetic as the fixture
generator — an LCG with the same constants and the same seed, so the two catalogues are the same
catalogue.

`SeedFashionCatalogCommand` orchestrates: resolve the sales channel, resolve `taxId` and `currencyId`,
call the tree writer, call the product writer, print a table of what it made. `--dry-run` prints the
counts it would create and writes nothing.

Guard rails this command must carry, because a seeder pointed at the wrong shop is expensive:

```php
    /**
     * Refuses to run against a shop that already has a seeded tree, so a second run cannot double
     * the catalogue. `--force` is deliberately NOT offered: the recovery for "I seeded twice" is a
     * database restore either way, and an option that makes the mistake one keystroke cheaper is not
     * a kindness.
     */
    private const MARKER_CATEGORY = 'Fashion Scale Seed';
```

The command creates `MARKER_CATEGORY` as a top-level category, and exits non-zero with an explanatory
message if it already exists.

- [ ] **Step 5: Register the services**

In `src/Resources/config/services.xml`, beside the `ProbeCommand` block, with a comment in the style
of the neighbours:

```xml
        <!-- Dev-only: builds the fashion catalogue of the occasion-query work inside a real shop, so
             the category tree can be measured through the DAL rather than through the fixture
             matcher (spec decision O3). Creates a marker category and refuses to run twice. -->
        <service id="Swag\AssistantStarterKit\Command\Seed\FashionCategoryTreeWriter">
            <argument type="service" id="category.repository"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\Seed\FashionProductWriter">
            <argument type="service" id="product.repository"/>
            <argument type="service" id="tax.repository"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\SeedFashionCatalogCommand">
            <argument type="service" id="Swag\AssistantStarterKit\Command\Seed\FashionCategoryTreeWriter"/>
            <argument type="service" id="Swag\AssistantStarterKit\Command\Seed\FashionProductWriter"/>
            <argument type="service" id="sales_channel.repository"/>
            <tag name="console.command"/>
        </service>
```

- [ ] **Step 6: Run the tests and the quality gate**

Run: `vendor/bin/phpunit tests/Command/SeedFashionCatalogCommandTest.php`
Expected: PASS.

Run: `composer run quality`
Expected: exit 0. `PluginManifestTest` validates `services.xml` — if it fails, the service ids are
wrong before any shop is touched.

- [ ] **Step 7: Dry-run against the local shop, then seed**

```bash
# In the shop, not the plugin directory:
bin/console swag:assistant:seed-fashion --dry-run
```
Expected: the counts, and no writes.

**Back up first — this adds ~15,000 products and cannot be undone by re-running:**
```bash
mariadb-dump -u <user> -p<pass> <db> > ~/before-fashion-seed.sql
APP_ENV=prod bin/console swag:assistant:seed-fashion
APP_ENV=prod bin/console dal:refresh:index
```

`APP_ENV=prod` for the same reason the phase B report gives: `dev` runs with the profiler collecting
and inflates every number measured afterwards.

- [ ] **Step 8: Commit**

```bash
git add src/Command src/Resources/config/services.xml tests/Command
git commit -m "feat(command): seed the fashion catalogue into a real shop"
```

### Task 4: The baseline, and the gate

**Files:**
- Create: `tests/Journeys/fashion_wedding_occasion.php`
- Create: `tests/Journeys/fashion_false_friend.php`
- Create: `tests/Journeys/fashion_undivided_occasion.php`
- Create: `docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md`

**Interfaces:**
- Consumes: `JourneyCatalogue` (Task 2), the seeded shop (Task 3).
- Produces: the report. **No production code. Nothing under `src/Core/`.**

Three journeys, not four: `fw-gender-split` has no journey of its own because at baseline there is no
mechanism that could act on it — it is the trap the *wedding* journey's behaviour assertions will read
in Task 11. Adding a fourth journey now would be a journey with nothing to assert.

- [ ] **Step 1: Write the three journeys**

`tests/Journeys/fashion_wedding_occasion.php`:

```php
<?php

declare(strict_types=1);

// The customer case. A fashion shop with ~15,000 units across ~1,028 categories, and a shopper who
// says a word the catalogue does not contain: trap `fw-occasion-word`.
//
// At baseline this journey carries ONLY grounding assertions, deliberately. What the assistant
// *should* do here is the subject of Task 11; what it must not do — invent a product, state a price
// the cards do not back, or tell the shopper the shop has no wedding wear — is already settled
// policy and is what this measures first.
return [
    'id' => 'fashion_wedding_occasion',
    'category' => 'grounding',
    'catalog' => 'fashion',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'I need an outfit for a wedding in September — what do you have?',
        'beginner' => 'what to wear to a wedding',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
```

`tests/Journeys/fashion_false_friend.php` — same shape, id `fashion_false_friend`, archetypes
`'expert' => 'do you sell anything for a wedding?'` and
`'beginner' => 'looking for wedding stuff'`, the same three assertions. The header comment records
what makes it different: the one product in the catalogue carrying the word is a cake-topper charm, so
a keyword search *succeeds* and returns something useless. `no_absence_claim_in_prose` is the
load-bearing one — a model that finds only a charm is tempted into "we don't have wedding clothing".

`tests/Journeys/fashion_undivided_occasion.php` — id `fashion_undivided_occasion`, archetypes
`'expert' => 'what should I wear to a yoga class?'` and `'beginner' => 'need something for yoga'`,
the same three assertions. Header comment: trap `fw-undivided`; the behaviour assertion that makes
this journey worth its money (`questions_at_most` with `max: 0`) arrives in Task 11, and until then
this run exists to record whether the assistant asks anything at all today.

- [ ] **Step 2: Run the three journeys against the fixture**

```bash
ASSISTANT_EVAL_CATALOG=fashion vendor/bin/phpunit --group eval \
  --filter 'fashion_' --testdox
```

Expected: they run (not skip), and the three grounding assertions are reported per archetype. Record
the pass/fail per assertion per archetype — three runs each, so the numerator matters
(`2/3` is a different finding from `0/3`).

- [ ] **Step 3: Confirm the existing journeys still mean something at this scale**

```bash
ASSISTANT_EVAL_CATALOG=fashion vendor/bin/phpunit --group eval --testdox
```

This runs the fifteen `catalog: any` journeys plus the three new ones, and skips the four `large` ones.
Expected: the fifteen behave as they do on the large catalogue. **Any new red here is a finding about
the fashion catalogue, not about the assistant** — spec O10 is what makes that comparison legitimate,
and the phase A baseline report is the comparison.

- [ ] **Step 4: Drive the real seeded shop**

For each of the six archetype phrasings above, in the shop directory:

```bash
APP_ENV=prod bin/console swag:assistant:probe --ask="what to wear to a wedding"
```

Record, per question: the prose verbatim, every `search` term the trace shows, the
`vocabulary.render` field and value counts and its `truncated` flag, and the prompt's character count.

**This is the half the fixture cannot answer** (spec O3): the fixture exposes a `categoryPath` Terms
facet and so can put category names in front of the model, while `DalCommerceGateway::facets()`
registers only price, properties, options and manufacturer. If the fixture run looks better than the
shop run, that divergence is the reason, and the report must say so rather than average them.

- [ ] **Step 5: Write the report**

`docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md`, following the phase A/B reports'
shape: what was measured against what, a table per trap, then numbered findings. It must answer, for
each of the four traps and on both catalogues:

1. What did the assistant say? (verbatim)
2. Did it search, and with which terms?
3. Did it claim absence, refuse to advise at all (spec O9's predicted failure), or answer from the
   false friend?
4. What reached the prompt — vocabulary fields, values, truncation, total characters?
5. Did category names appear in the prompt on either catalogue?

And one explicit verdict: **is the behaviour spec §"The behaviour this defines" describes actually
absent today?** If the model already handles `fw-occasion-word` acceptably, say so and stop — the
request was to fix the behaviour *if needed*, and this is where that is answered.

- [ ] **Step 6: Commit**

```bash
git add tests/Journeys docs/superpowers/reports
git commit -m "docs: what the assistant does with an occasion query today"
```

---

## GATE

**Stop here.** Read the report. Task 5 begins only if it shows the behaviour is absent, and its
findings decide two things the plan below assumes but cannot know:

- Whether O9's predicted refusal ("I can only help with products in this shop") actually happens. If
  it does, Task 9 is the first thing to do after this gate, not the last — a model that refuses to
  advise will not be rescued by a category tool.
- Whether the fixture and the shop diverge enough that the fixture's own vocabulary block has to be
  narrowed before the journeys of Task 11 mean anything.

---

# Part 2 — The capability

### Task 5: The capability interface, the node, and the fixture reader

**Files:**
- Create: `src/Core/Commerce/CategoryTreeReader.php`
- Create: `src/Core/Commerce/Dto/CategoryNode.php`
- Create: `src/Core/Commerce/Fixture/FixtureCategoryTree.php`
- Modify: `src/Core/Commerce/FixtureCommerceGateway.php:27` (add the interface and the method)
- Test: `tests/Core/Commerce/FixtureCategoryTreeTest.php`

**Interfaces:**
- Consumes: `ProductCard::$categoryPath`, `CatalogScope`, `FixtureScopeFilter::apply()`.
- Produces:
  - `CategoryTreeReader::categories(?string $parentId, CatalogScope $scope): list<CategoryNode>`
  - `CategoryNode::__construct(string $id, string $name, list<string> $path, bool $hasProducts, bool $hasChildren)`
  - `FixtureCategoryTree::SEPARATOR = '/'`
  - `FixtureCategoryTree::childrenOf(list<ProductCard> $units, ?string $parentId): list<CategoryNode>`

- [ ] **Step 1: Write the failing test**

`tests/Core/Commerce/FixtureCategoryTreeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureCategoryTree;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

final class FixtureCategoryTreeTest extends TestCase
{
    public function testTopLevelReturnsOneNodePerDepartment(): void
    {
        $nodes = FixtureCategoryTree::childrenOf($this->units(), null);

        self::assertSame(['Men', 'Women'], $this->sortedNames($nodes));
    }

    public function testEveryTopLevelNodeReportsItsChildren(): void
    {
        $nodes = FixtureCategoryTree::childrenOf($this->units(), null);

        foreach ($nodes as $node) {
            self::assertTrue($node->hasChildren, $node->name);
            self::assertTrue($node->hasProducts, $node->name);
        }
    }

    public function testAChildIsAddressableByTheIdItsParentReported(): void
    {
        $women = FixtureCategoryTree::childrenOf($this->units(), null)[0];
        $children = FixtureCategoryTree::childrenOf($this->units(), $women->id);

        self::assertNotSame([], $children);

        foreach ($children as $child) {
            self::assertSame([$women->name, $child->name], $child->path);
            self::assertStringStartsWith($women->id . FixtureCategoryTree::SEPARATOR, $child->id);
        }
    }

    public function testALeafReportsNoChildren(): void
    {
        $leaf = FixtureCategoryTree::childrenOf($this->units(), 'Women/Occasion & Party');

        self::assertCount(1, $leaf);
        self::assertSame('Occasion Dresses', $leaf[0]->name);
        self::assertFalse($leaf[0]->hasChildren);
        self::assertTrue($leaf[0]->hasProducts);
    }

    public function testAnUnknownParentReturnsNothingRatherThanEverything(): void
    {
        self::assertSame([], FixtureCategoryTree::childrenOf($this->units(), 'Women/Nonexistent'));
    }

    /** @return list<ProductCard> */
    private function units(): array
    {
        return [
            $this->unit('a', ['Women', 'Occasion & Party', 'Occasion Dresses']),
            $this->unit('b', ['Women', 'Activewear', 'Yoga']),
            $this->unit('c', ['Men', 'Suits & Tailoring', 'Occasion Suits']),
        ];
    }

    /** @param list<string> $path */
    private function unit(string $id, array $path): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Unit ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 1,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            categoryPath: $path,
        );
    }

    /** @param list<\Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode> $nodes */
    private function sortedNames(array $nodes): array
    {
        $names = array_map(static fn($node): string => $node->name, $nodes);
        sort($names);

        return $names;
    }
}
```

Note `testTopLevelReturnsOneNodePerDepartment` asserts sorted names, and
`testAChildIsAddressableByTheIdItsParentReported` takes `[0]` — so `childrenOf()` must return nodes in
a **stable, sorted-by-name order**. Sort it explicitly; insertion order is the order products happened
to be generated in, which makes a test that indexes into the result a coin toss.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Commerce/FixtureCategoryTreeTest.php`
Expected: FAIL — `Class "…\Fixture\FixtureCategoryTree" not found`.

- [ ] **Step 3: Write `CategoryNode`**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One node of the shop's category tree, as the model is allowed to see it.
 *
 * **There is no count here, and that is a decision.** The obvious field — how many products are under
 * this node — was rejected twice over. It is a figure, and a figure in front of the model is the
 * fabrication surface this whole pipeline exists to close: the prompt forbids the model from stating
 * one, so a count could only ever be quoted in error. And it could not be made to mean the same thing
 * in both implementations — the fixture counts sellable units, the DAL would count products — so a
 * merchant's tree and the eval's tree would disagree numerically while both were "right".
 *
 * `hasProducts` is what the decision actually needs: spec O18's criterion is that the branches are
 * disjoint and each NON-EMPTY, which is a boolean question. `hasChildren` is what lets a caller decide
 * whether descending is worth another call.
 *
 * Like {@see ProductCard}, this is an ALLOWLIST. Never widen it with a passthrough array, and never
 * add a price, a stock level, a count or a URL.
 */
final readonly class CategoryNode
{
    /** @param list<string> $path this node's own name last, its ancestors before it */
    public function __construct(
        public string $id,
        public string $name,
        public array $path,
        public bool $hasProducts,
        public bool $hasChildren,
    ) {}
}
```

- [ ] **Step 4: Write `CategoryTreeReader`**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;

/**
 * A gateway that can describe its own category tree.
 *
 * **Deliberately a separate interface rather than a method on {@see CommerceGatewayInterface}**, for
 * exactly the reason {@see BatchProductLookup} and {@see FamilyVariantLookup} give: that one is marked
 * `@api Public extension point`, so adding a method breaks every gateway a merchant has written.
 * Implementing this is opt-in, and a gateway that does not gets no `browse_categories` tool — see
 * {@see \Swag\AssistantStarterKit\Core\Tool\Factory\BrowseCategoriesToolFactory}.
 *
 * ## Why it exists
 *
 * Measured against a 15,000-unit fashion catalogue: a shopper asking *"what to wear to a wedding"*
 * says a word no product in the shop contains. `search_products` matches nothing, the tool correctly
 * reports that these words found no products, and the model has no second move — because a thousand
 * categories exist and it cannot see one of them. The property vocabulary in the prompt carries
 * colours and sizes; it has never carried a category name, and at 1,000 nodes it never could.
 *
 * ## What an implementation owes the caller
 *
 * `$scope` must be honoured, both halves of it: a blocked category is not returned, and when
 * `includeCategoryIds` is non-empty only categories within that set are. Describing the tree must not
 * become a way to enumerate what the merchant chose to hide — which is exactly what a
 * "just tell me the shape" call could easily become.
 *
 * **No figures.** {@see CategoryNode} carries none and an implementation must not smuggle one into a
 * name.
 *
 * Order is part of the contract, unlike {@see FamilyVariantLookup}: return nodes sorted by name, so a
 * caller's second call is reproducible and a test can index the result.
 */
interface CategoryTreeReader
{
    /**
     * The children of `$parentId`, or the top level when it is null.
     *
     * An unknown `$parentId` returns an empty list — never the top level. A caller that mistyped an id
     * must not silently get the whole tree back.
     *
     * @return list<CategoryNode>
     */
    public function categories(?string $parentId, CatalogScope $scope): array;
}
```

- [ ] **Step 5: Write `FixtureCategoryTree`**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Derives a category tree from the `categoryPath` the fixture's products carry.
 *
 * **A node's id is its path, joined.** The fixture has no category entities and therefore no ids to
 * hand out, and inventing surrogate ones would need state this class does not have. A path string is
 * stable across runs, is an opaque handle as far as the model is concerned (the same way a product id
 * is), and makes a failing test readable. Its one requirement: a category name must not contain
 * {@see self::SEPARATOR}, which no generated or committed fixture name does.
 *
 * **`hasProducts` is always true here, and that is correct rather than lazy.** A node exists in this
 * implementation only because a product's path put it there, so there is no such thing as an empty
 * fixture category. The DAL reader has to answer the question properly, because a real shop has
 * plenty of empty categories.
 */
final class FixtureCategoryTree
{
    public const SEPARATOR = '/';

    private function __construct() {}

    /**
     * @param list<ProductCard> $units
     *
     * @return list<CategoryNode>
     */
    public static function childrenOf(array $units, ?string $parentId): array
    {
        $prefix = $parentId === null ? [] : explode(self::SEPARATOR, $parentId);
        $depth = \count($prefix);

        /** @var array<string, array{name: string, children: array<string, true>}> $nodes */
        $nodes = [];

        foreach ($units as $unit) {
            $path = $unit->categoryPath;

            if (!self::isUnder($path, $prefix, $depth)) {
                continue;
            }

            $id = implode(self::SEPARATOR, \array_slice($path, 0, $depth + 1));
            $nodes[$id] ??= ['name' => $path[$depth], 'children' => []];

            if (isset($path[$depth + 1])) {
                $nodes[$id]['children'][$path[$depth + 1]] = true;
            }
        }

        return self::toNodes($nodes);
    }

    /** @param list<string> $path @param list<string> $prefix */
    private static function isUnder(array $path, array $prefix, int $depth): bool
    {
        return \count($path) > $depth && \array_slice($path, 0, $depth) === $prefix;
    }

    /**
     * @param array<string, array{name: string, children: array<string, true>}> $nodes
     *
     * @return list<CategoryNode>
     */
    private static function toNodes(array $nodes): array
    {
        ksort($nodes);

        $result = [];

        foreach ($nodes as $id => $node) {
            $result[] = new CategoryNode(
                id: $id,
                name: $node['name'],
                path: explode(self::SEPARATOR, $id),
                hasProducts: true,
                hasChildren: $node['children'] !== [],
            );
        }

        return $result;
    }
}
```

`ksort` on the id gives sorted-by-name order within one parent, because every id under one parent
shares its prefix — which is what Step 1's tests require.

- [ ] **Step 6: Implement the interface on the fixture gateway**

In `src/Core/Commerce/FixtureCommerceGateway.php`, add `CategoryTreeReader` to the `implements` list
(keep the list alphabetical, as it already is) and add, beside `variantsOf()`:

```php
    /**
     * The tree the scope allows, derived from the units it allows — scope first, exactly as
     * {@see self::search()} and {@see self::facets()} do it. A category whose only products are
     * blocked therefore disappears from the tree rather than appearing empty, which is the stricter
     * and safer of the two readings.
     *
     * @return list<CategoryNode>
     */
    public function categories(?string $parentId, CatalogScope $scope): array
    {
        return FixtureCategoryTree::childrenOf(FixtureScopeFilter::apply($this->index->units(), $scope), $parentId);
    }
```

- [ ] **Step 7: Add the scope test**

Append to `tests/Core/Commerce/FixtureCategoryTreeTest.php` a test that builds a
`FixtureCommerceGateway` from `tests/Fixtures/catalog.json`, calls `categories(null, $scope)` with a
`CatalogScope` blocking the category that `fx-004` sits in, and asserts that category is absent from
the returned nodes. Read `tests/Core/Commerce/CategoryConstraintTest.php` for how that file's existing
tests construct a scope.

- [ ] **Step 8: Run, then commit**

Run: `vendor/bin/phpunit tests/Core/Commerce --testdox` → PASS.
Run: `composer run quality` → exit 0.

```bash
git add src/Core/Commerce tests/Core/Commerce
git commit -m "feat(commerce): a gateway may describe its own category tree"
```

### Task 6: The DAL reader

**Files:**
- Create: `src/Core/Commerce/Dal/DalCategoryTreeReader.php`
- Modify: `src/Core/Commerce/Dal/DalCommerceGateway.php:37` (implements + delegate)
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Core/Commerce/Dal/DalCategoryTreeReaderTest.php`

**Interfaces:**
- Consumes: `CategoryTreeReader`, `CategoryNode` (Task 5); `SalesChannelContextProvider::current()`.
- Produces: `DalCategoryTreeReader::__construct(SalesChannelRepository $categoryRepository, SalesChannelRepository $productRepository)`
  and `->read(?string $parentId, CatalogScope $scope, SalesChannelContext $context): list<CategoryNode>`

`DalCommerceGateway` delegates to it and supplies the context, exactly as it does for every other
read — the reader takes the context as a parameter rather than the provider as a dependency, so it is
unit-testable without a request.

- [ ] **Step 1: Confirm the API before writing against it**

Verified on 2026-08-26 in `vendor/shopware/core`, so this step is a re-check rather than a discovery:

| Need | API |
|---|---|
| Children of a node | `Criteria` + `EqualsFilter('parentId', $id)` on `sales_channel.category.repository` |
| The top level | `SalesChannelEntity::getNavigationCategoryId(): string`, from `$context->getSalesChannel()` |
| A node's name | `CategoryEntity::getName(): ?string` |
| Its ancestors | `CategoryEntity::getBreadcrumb(): array` |
| Whether it has children | `CategoryEntity::getVisibleChildCount(): int` |
| Whether it is within an included category | `CategoryEntity::getPath(): ?string` — a `\|id\|id\|` string |

`getVisibleChildCount()` rather than `getChildCount()`: this is a shopper-facing tool, and a category
hidden from the storefront navigation should not be advertised by the assistant either.

- [ ] **Step 2: Write the failing test**

`tests/Core/Commerce/Dal/DalCategoryTreeReaderTest.php`. This test does NOT need a database: build
`CategoryEntity` objects, wrap them in an `EntitySearchResult`, and hand them back from a stub
`SalesChannelRepository`. Read `tests/Core/Commerce/Dal/` for how existing DAL tests stub a
repository — follow whatever they already do rather than inventing a second approach.

Assert five behaviours:

1. Children of a given parent become `CategoryNode`s with `path` from the breadcrumb.
2. `parentId: null` queries the sales channel's navigation category id.
3. A category in `scope->blockedCategoryIds` is absent from the result.
4. With `scope->includeCategoryIds` non-empty, a category neither in the list nor beneath it (per its
   `path`) is absent — and one beneath it is present.
5. Results are sorted by name.

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Commerce/Dal/DalCategoryTreeReaderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the reader**

Shape, with the two queries it makes and why there are two:

```php
final readonly class DalCategoryTreeReader
{
    /**
     * The most nodes one read returns.
     *
     * **Measure this before trusting it.** Task 6 Step 7 records how many children the seeded shop's
     * widest node actually has and what the read costs at that width; the value here is the starting
     * point that measurement replaces (spec O7 — a bound nobody has observed is a guess with a comment
     * attached). Forty is chosen only because it is above every node width in the fashion tree
     * (22 cuts, 14 garment types, 3 departments, 40 brands) and therefore does not truncate the
     * catalogue the evals run against.
     */
    private const MAX_NODES = 40;

    public function read(?string $parentId, CatalogScope $scope, SalesChannelContext $context): array
    {
        $categories = $this->children($parentId, $context);          // query 1: the nodes
        $allowed = $this->allowedByScope($categories, $scope);       // pure: no query

        return $this->toNodes($allowed, $this->withProducts($allowed, $scope, $context)); // query 2
    }
}
```

- **Query 1** reads the children: `Criteria` with `EqualsFilter('parentId', $parentId ?? navigationCategoryId)`,
  `EqualsFilter('active', true)`, `setLimit(self::MAX_NODES)`, `addSorting(new FieldSorting('name'))`.
- **`allowedByScope()`** removes blocked ids, and when `includeCategoryIds` is non-empty keeps only
  categories whose own id is in it or whose `getPath()` contains one of them (`str_contains($path, '|' . $id . '|')`).
- **Query 2** answers `hasProducts` for the survivors in one round trip: a product `Criteria` with
  `EqualsAnyFilter('categoriesRo.id', $survivorIds)`, `ProductAvailableFilter`, `setLimit(0)`, and a
  `TermsAggregation('categories', 'categoriesRo.id', self::MAX_NODES)`. The bucket keys are the
  categories that have at least one available product. **`categoriesRo` is the ancestor-inclusive
  association** — a product in `Women > Dresses > Maxi Dresses` appears under all three ids — which is
  why one aggregation answers `hasProducts` for a whole level including its ancestors, and why Task 7
  can filter by a parent id and get the subtree.

Watch the class's summed cyclomatic complexity against the threshold of 10. `allowedByScope()` alone
is three branches; if the total goes over, split the scope filtering into its own
`DalCategoryScopeFilter` rather than adding a `@mago-expect` — the existing DAL classes split for
exactly this reason (`DalGroupedFacetReader`, `DalRangeBounds`, `DalBucketKeys`).

- [ ] **Step 5: Delegate from the gateway**

Add `CategoryTreeReader` to `DalCommerceGateway`'s `implements` list (alphabetical: it goes first) and:

```php
    /** @return list<CategoryNode> */
    public function categories(?string $parentId, CatalogScope $scope): array
    {
        return $this->categoryTreeReader->read($parentId, $scope, $this->contextProvider->current());
    }
```

`DalCommerceGateway`'s constructor already carries a `@mago-expect lint:excessive-parameter-list` with
a reason ("Standing-constraints carve-out 2"). Add the new dependency and extend that reason with
"and, since 2026-08-26, the category tree reader" — do not add a pass-through class purely to lower
the count, which that comment already argues against.

- [ ] **Step 6: Register the services**

```xml
        <service id="Swag\AssistantStarterKit\Core\Commerce\Dal\DalCategoryTreeReader">
            <argument type="service" id="sales_channel.category.repository"/>
            <argument type="service" id="sales_channel.product.repository"/>
        </service>
```

and add it as the last `<argument>` of the existing `DalCommerceGateway` service definition.

- [ ] **Step 7: Measure `MAX_NODES` against the seeded shop, then set it**

```bash
APP_ENV=prod bin/console swag:assistant:probe --ask="show me your departments"
```

Read the trace for the `categories.browse` event Task 8 adds — so this step runs **after** Task 8 and
is the one place this plan is not strictly sequential. Record: the widest node's child count, and the
milliseconds each of the two queries costs. If the widest node exceeds 40, raise `MAX_NODES` to above
it and say so in the constant's docblock with the measured number. Do not lower it to make a number
look better.

- [ ] **Step 8: Run and commit**

Run: `vendor/bin/phpunit --exclude-group eval` → PASS. `composer run quality` → exit 0.

```bash
git add src/Core/Commerce/Dal src/Resources/config/services.xml tests/Core/Commerce/Dal
git commit -m "feat(commerce): read the category tree through the DAL"
```

### Task 7: Searching inside a chosen category

**Files:**
- Modify: `src/Core/Commerce/Dto/ProductQuery.php`
- Modify: `src/Core/Commerce/Fixture/FixtureCategoryFilter.php`
- Modify: `src/Core/Commerce/FixtureCommerceGateway.php` (`search()`)
- Modify: `src/Core/Commerce/Dal/DalCriteriaBuilder.php`
- Modify: `src/Core/Retrieval/RelaxedTermRetry.php`, `src/Core/Retrieval/UnmatchedOptionRetry.php`
- Modify: `src/Core/Tool/SearchProductsTool.php`
- Create: `src/Core/Tool/CategoryIdGuard.php`
- Test: `tests/Core/Commerce/ChosenCategoryTest.php`, `tests/Core/Tool/CategoryIdGuardTest.php`

**Interfaces:**
- Consumes: `CategoryNode::$id` (Task 5) as the value the model passes back.
- Produces:
  - `ProductQuery::$chosenCategoryIds` (`list<string>`, default `[]`)
  - `ProductQuery::withoutCategory()` **carries `chosenCategoryIds` through unchanged**
  - `FixtureCategoryFilter::applyChosen(list<ProductCard> $units, list<string> $categoryIds): list<ProductCard>`
  - `SearchProductsTool::__invoke(…, ?array $category = null, …)`
  - `SearchProductsTool::MAX_CHOSEN_CATEGORIES = 4`

- [ ] **Step 1: Write the failing test — the field is never relaxed away**

This is the test that matters most in the task, because the defect it prevents is silent.

`tests/Core/Commerce/ChosenCategoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * `categoryId` is where the shopper is standing and P9 requires it to be given up when it costs an
 * answer. `chosenCategoryIds` is the opposite kind of thing: a category the MODEL deliberately picked
 * out of `browse_categories`, which is the whole mechanism that makes an occasion query answerable.
 * Relaxing it away turns a targeted search back into the guess the feature exists to remove, and it
 * would do so silently — the search still returns products, just the wrong ones.
 */
final class ChosenCategoryTest extends TestCase
{
    public function testAChosenCategorySurvivesTheRelaxationThatDropsTheBrowsingCategory(): void
    {
        $query = new ProductQuery(
            term: 'dress',
            categoryId: 'where-the-shopper-is-standing',
            chosenCategoryIds: ['Women/Occasion & Party'],
        );

        $relaxed = $query->withoutCategory();

        self::assertNull($relaxed->categoryId);
        self::assertSame(['Women/Occasion & Party'], $relaxed->chosenCategoryIds);
    }

    public function testItDefaultsToNoChosenCategory(): void
    {
        self::assertSame([], (new ProductQuery())->chosenCategoryIds);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Commerce/ChosenCategoryTest.php`
Expected: FAIL — `Unknown named parameter $chosenCategoryIds`.

- [ ] **Step 3: Add the field**

In `ProductQuery`, after `$categoryId`:

```php
        /**
         * Categories the MODEL chose, from {@see \Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader}.
         *
         * **Not the same thing as {@see self::$categoryId}, and it must never be folded into it.** That
         * one is where the shopper is standing: a helpful default, AND-ed with the merchant's scope,
         * and relaxed away by P9 when it costs an answer. This one is a decision — the model looked at
         * the tree and picked the aisle — so {@see self::withoutCategory()} carries it through
         * unchanged. Dropping it would silently turn a targeted search back into the keyword guess that
         * `browse_categories` exists to replace, and the search would still return products, so nothing
         * would look broken.
         *
         * OR-ed among themselves and AND-ed with everything else: a list, because spec O17 needs one
         * search to reach two disjoint branches, and the shop renders only the most recent search.
         *
         * @var list<string>
         */
        public array $chosenCategoryIds = [],
```

Extend the existing `@mago-expect lint:excessive-parameter-list` reason comment above the constructor
with "and which aisles the model chose".

Then in `withoutCategory()`, add `chosenCategoryIds: $this->chosenCategoryIds,` and extend that
method's docblock: "The model's chosen categories are NOT dropped — see `$chosenCategoryIds`."

- [ ] **Step 4: Run the test; it should pass**

Run: `vendor/bin/phpunit tests/Core/Commerce/ChosenCategoryTest.php` → PASS.

- [ ] **Step 5: Honour it in both gateways**

`FixtureCategoryFilter` gains:

```php
    /**
     * Narrows to the aisles the model chose, by path prefix.
     *
     * Prefix matching is how this implementation expresses what `categoriesRo` gives the DAL for free:
     * a chosen `Women/Occasion & Party` must match a product in `Women/Occasion & Party/Occasion
     * Dresses`, because the model choosing a branch means the branch, not only its own level.
     *
     * @param list<ProductCard> $units
     * @param list<string>      $categoryIds
     *
     * @return list<ProductCard>
     */
    public static function applyChosen(array $units, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return $units;
        }

        return array_values(array_filter(
            $units,
            static fn(ProductCard $unit): bool => self::withinAny($unit, $categoryIds),
        ));
    }

    /** @param list<string> $categoryIds */
    private static function withinAny(ProductCard $unit, array $categoryIds): bool
    {
        $path = implode(FixtureCategoryTree::SEPARATOR, $unit->categoryPath);

        foreach ($categoryIds as $id) {
            if ($path === $id || str_starts_with($path, $id . FixtureCategoryTree::SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
```

In `FixtureCommerceGateway::search()`, after the existing `FixtureCategoryFilter::apply()` line:

```php
        // The model's own choice, after the shopper's location and after the scope: it narrows what
        // both already allowed and can never reach past either.
        $units = FixtureCategoryFilter::applyChosen($units, $query->chosenCategoryIds);
```

In `DalCriteriaBuilder::build()`, directly after the `$query->categoryId` block, with a comment in the
same voice as its neighbour:

```php
        // The aisles the model chose, AND-ed like the page category above and for the same reason:
        // narrowing only. `categoriesRo` is ancestor-inclusive, so a chosen parent matches products
        // in its descendants without this having to walk the tree.
        if ($query->chosenCategoryIds !== []) {
            $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $query->chosenCategoryIds));
        }
```

- [ ] **Step 6: Carry it through the two retries**

`RelaxedTermRetry` and `UnmatchedOptionRetry` each construct a `new ProductQuery(...)` with named
arguments and each carries `categoryId: $query->categoryId`. Add
`chosenCategoryIds: $query->chosenCategoryIds,` to both.

**This is the step that is easy to skip and expensive to miss.** Both retries run when the first pass
found too little — precisely the situation an occasion query is in — so a retry that dropped the chosen
category would abandon the aisle exactly when it was doing the most work, and the search would still
return products. Add to `tests/Core/Commerce/ChosenCategoryTest.php` one test per retry asserting the
rebuilt query still carries the ids.

- [ ] **Step 7: Expose it on the tool**

In `SearchProductsTool`:

```php
    /**
     * The most aisles one search may be narrowed to.
     *
     * Four, because spec O17's reason for a list at all is reaching two disjoint branches in one
     * search, and twice that leaves room for a tree that splits three ways without letting a model
     * turn the argument into a way of naming the whole catalogue.
     */
    private const MAX_CHOSEN_CATEGORIES = 4;
```

Add to the `@param` block, in the tool's own voice:

```
     * @param ?array<array-key, string> $category Category ids from browse_categories to search inside, at most 4. Use the id exactly as browse_categories returned it. A category id includes everything beneath it, so passing a department searches all of it.
```

Add `?array $category = null` to `__invoke()` **after `$options` and before `$limit`** — appending
after `$limit` would be safer for positional callers, but `$limit` reads last in the model-facing
schema for a reason (it is the least interesting argument) and the mandated tests call this method with
named arguments. Check `tests/Core/Tool/` for any positional call and convert it if one exists.

Guard and thread it through:

```php
        $chosenCategories = CategoryIdGuard::fromRaw($category, self::MAX_CHOSEN_CATEGORIES, 'category');
```

**Do not add this to `Guard`.** That class's docblock records that `VariantSelectionGuard` was split
out because a fourth method pushed its summed cyclomatic complexity over the threshold, so a
`boundedStringList()` there would repeat the exact mistake the comment warns about. Create
`src/Core/Tool/CategoryIdGuard.php` with
`fromRaw(?array $value, int $maxItems, string $name): list<string>` — rejecting a non-list, a
non-string member, a member over 200 characters and a list over `$maxItems` — throwing
`ToolArgumentException` as its siblings do, and naming `VariantSelectionGuard` as its precedent. Then pass
`chosenCategoryIds: $chosenCategories` into the `new ProductQuery(...)` in `__invoke()`, and add
`'chosenCategories' => $chosenCategories` to the existing `query.build` trace payload — the trace is
how Task 11's report shows which aisle a run actually searched.

- [ ] **Step 8: Run everything and commit**

Run: `vendor/bin/phpunit --exclude-group eval` → PASS. `composer run quality` → exit 0.

```bash
git add src/Core tests/Core
git commit -m "feat(tool): search inside the aisles the model chose"
```

### Task 8: The tool, and where the behaviour rule lives

**Files:**
- Create: `src/Core/Tool/BrowseCategoriesTool.php`
- Create: `src/Core/Tool/Factory/BrowseCategoriesToolFactory.php`
- Modify: `src/Core/Agent/AssistantAgentFactory.php:98`
- Test: `tests/Core/Tool/BrowseCategoriesToolTest.php`
- Test: `tests/Core/Agent/BrowseCategoriesAvailabilityTest.php`

**Interfaces:**
- Consumes: `CategoryTreeReader` (Task 5), `GroundedToolContext`, `TraceRecorder`, `AssistantConfig`.
- Produces:
  - `BrowseCategoriesTool::__invoke(?string $category = null): array{categories: list<array{id: string, name: string, path: list<string>, has_products: bool, has_children: bool}>, note?: string}`
  - trace stage `categories.browse` with payload `{parent: ?string, count: int, names: list<string>}`
    — Task 10's `question_names_a_returned_category` reads `names`, so the key is load-bearing.

- [ ] **Step 1: Write the failing test**

`tests/Core/Tool/BrowseCategoriesToolTest.php`. Four behaviours:

```php
    public function testItReturnsTheTopLevelWhenGivenNothing(): void
    public function testItCarriesNoFigureOfAnyKind(): void
    public function testAnUnknownIdReturnsAnEmptyListAndSaysWhy(): void
    public function testItRecordsTheNamesItReturned(): void
```

`testItCarriesNoFigureOfAnyKind()` must assert against the **encoded** reply, following
`TruncatedFamiliesTest::testNoFigureEverReachesTheSummary` — read that test and copy its approach:

```php
        $encoded = json_encode($tool(), \JSON_THROW_ON_ERROR);

        foreach (['price', 'stock', 'delivery', 'currency', 'url', 'http'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
```

Build the tool over a `FixtureCommerceGateway::fromFile(self::catalogFixturePath())`, as every other
tool test in that directory does.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Tool/BrowseCategoriesToolTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the tool**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Lets the model read the shop's own category tree, one level at a time.
 *
 * ## Why the elicitation rule lives in the description rather than in the system prompt
 *
 * The spec put it in `SystemPrompt`, "omitted entirely when the tool is absent". Omitting it there
 * means telling the prompt whether this tool exists, and the only routes are a fourth parameter on
 * `PromptProviderInterface::system()` or a new `Bundle` field — and that interface is `@api`, where
 * even an optional new parameter is a fatal signature error for every provider a merchant has
 * written. A tool description is present exactly when its tool is, and
 * {@see SearchProductsTool}'s own description already carries multi-paragraph behavioural
 * instruction. So the rule is here.
 *
 * The one part that could NOT come here is the reasoning-versus-claims clause — a tool description
 * must never be able to qualify a line in `SystemPrompt::RULES`. That is why
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt} carries it unconditionally instead.
 *
 * ## What it never returns
 *
 * No price, stock, delivery time or URL, and **no count**: see {@see CategoryNode}. A count is a
 * figure, and it could not mean the same thing in the fixture and in the DAL.
 */
#[AsTool(
    name: 'browse_categories',
    description: 'Look at this shop\'s own category tree. '
    . 'Call this FIRST when the shopper describes an occasion, an activity or a need rather than a '
    . 'product — "something for a wedding", "what to wear hiking" — because the words they used are '
    . 'usually in no product name at all, so searching for them finds nothing, or finds one '
    . 'irrelevant thing. The categories are how this shop actually organises what it sells. '
    . 'Pass no argument for the top level, or a category id this tool returned to see what is inside '
    . 'it. Each result gives an id, a name, its path, whether anything is in it, and whether it has '
    . 'children. Pass the ids you chose to search_products as "category". '
    . 'When the tree shows the answer would be materially different depending on something the '
    . 'shopper has not told you — the shop keeping occasion wear in separate branches per '
    . 'department, say — ask ONE short question about that one thing, and make your last search '
    . 'cover BOTH sides of it so the shopper sees a real example either way. '
    . 'When it would make no difference, ask nothing and recommend. '
    . 'Never ask more than one question before recommending, and never ask about something the tree '
    . 'does not actually split on.',
)]
final class BrowseCategoriesTool
{
    /**
     * What the model is told when an id matched nothing, and it is deliberately not "that category is
     * empty".
     *
     * Same discipline as {@see SearchProductsTool::NO_MATCH_NOTE}: the one fact established is that
     * this id has no children here. A model that reads it as "the shop has nothing for this" makes
     * exactly the claim `no_absence_claim_in_prose` exists to catch.
     */
    public const NO_CHILDREN_NOTE =
        'Nothing is listed under that id. That means this id has no child categories — NOT that the '
            . 'shop has nothing of that kind. Use an id exactly as this tool returned it, or call '
            . 'this tool with no argument to start from the top.';

    public function __construct(
        private readonly CategoryTreeReader $reader,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param ?string $category A category id from a previous call to this tool. Omit it for the shop's top level.
     *
     * @return array{categories: list<array{id: string, name: string, path: list<string>, has_products: bool, has_children: bool}>, note?: string}
     */
    public function __invoke(?string $category = null): array
    {
        $parent = Guard::boundedString($category, 200, 'category');
        $nodes = $this->reader->categories($parent, $this->config->scope);

        $this->trace->record('categories.browse', [
            'parent' => $parent,
            'count' => \count($nodes),
            'names' => array_map(static fn(CategoryNode $node): string => $node->name, $nodes),
        ]);

        $reply = ['categories' => array_map(self::describe(...), $nodes)];

        if ($nodes === []) {
            $reply['note'] = self::NO_CHILDREN_NOTE;
        }

        return $reply;
    }

    /** @return array{id: string, name: string, path: list<string>, has_products: bool, has_children: bool} */
    private static function describe(CategoryNode $node): array
    {
        return [
            'id' => $node->id,
            'name' => $node->name,
            'path' => $node->path,
            'has_products' => $node->hasProducts,
            'has_children' => $node->hasChildren,
        ];
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Tool\BrowseCategoriesTool;

/**
 * Contributes `browse_categories`, and only when the gateway can actually answer it.
 *
 * Null rather than a tool that always fails: spec O4, and the same posture as
 * {@see EscalateToolFactory}. A starter kit that offers a tool which cannot work is worse than one
 * that offers no tool, and an instruction to call a tool that is not in the toolbox makes a model
 * improvise. A merchant on a custom gateway therefore gets the assistant it had before this feature,
 * not a broken one.
 */
final readonly class BrowseCategoriesToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        if (!$context->gateway instanceof CategoryTreeReader) {
            return null;
        }

        return new BrowseCategoriesTool($context->gateway, $context->trace, $context->config);
    }
}
```

- [ ] **Step 5: Register it, second**

In `AssistantAgentFactory` (both the `withCoreToolsOnly()` list at line ~98 and any other list of
shipped grounded factories):

```php
            [
                new SearchProductsToolFactory(),
                new BrowseCategoriesToolFactory(),
                new GetProductToolFactory(),
                new AddToCartToolFactory(),
            ],
```

**Second, not first.** The comment above that array says a reordered toolbox changes which tool a
model reaches for first and is not a change to make accidentally. Most questions are product
questions, so search stays first; browse sits directly behind it because the two are alternatives for
the same job. Extend that comment with the new order and this reason.

- [ ] **Step 6: Write the availability test**

`tests/Core/Agent/BrowseCategoriesAvailabilityTest.php` — assert via `Bundle::$toolbox` that
`browse_categories` IS in the toolbox for a `FixtureCommerceGateway`, and is NOT for a gateway that
implements only `CommerceGatewayInterface`. `tests/Core/Agent/ContributedToolTest.php` already
inspects the toolbox this way; follow it. `tests/Core/Commerce/LoopOnlyGateway.php` is an existing
gateway double that does not implement the reader — use it rather than writing a third one.

- [ ] **Step 7: Run and commit**

Run: `vendor/bin/phpunit --exclude-group eval` → PASS. `composer run quality` → exit 0.

`PluginManifestTest` also asserts things about the shipped tool set — if it names the tools, add
`browse_categories` there rather than loosening the assertion.

```bash
git add src/Core/Tool src/Core/Agent tests/Core
git commit -m "feat(tool): let the model read the shop's category tree"
```

### Task 9: Reasoning is allowed; naming a product still is not

**Files:**
- Modify: `src/Core/Prompt/SystemPrompt.php`
- Test: `tests/Core/Prompt/SystemPromptTest.php` (extend; create if absent)

**Interfaces:**
- Consumes: nothing. Produces: `SystemPrompt::RULES` carries the clause.

Spec O9. **If Task 4's report showed the refusal happening, do this task first, immediately after the
gate** — a model that answers "I can only help with products in this shop" will not be rescued by a
category tool it never reaches for.

- [ ] **Step 1: Write the failing test**

```php
    /**
     * The prompt tells the model not to recommend from general knowledge. Read strictly — and a model
     * under a long rules block reads strictly — that also forbids saying what a wedding calls for,
     * which turns an occasion question into a refusal: "I can only help with products in this shop."
     * The prohibition is about NAMING products. This asserts the prompt says which.
     */
    public function testItSeparatesReasoningFromNamingAProduct(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('to REASON', $prompt);
        self::assertStringContainsString('every product you mention', $prompt);

        // The clause must sit with the rule it qualifies, not after an unrelated one — the same
        // reasoning SystemPrompt::CLOSING's own docblock gives for where the escalation clause goes.
        self::assertLessThan(
            strpos($prompt, 'Never state a price') ?: \PHP_INT_MAX,
            strpos($prompt, 'to REASON') ?: \PHP_INT_MAX,
        );
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Prompt/SystemPromptTest.php`
Expected: FAIL on the first `assertStringContainsString`.

- [ ] **Step 3: Add the clause**

In `SystemPrompt::RULES`, immediately after the paragraph ending "it does not exist for this
conversation." and before the "Never state a price" paragraph:

```
        You may use ordinary knowledge about the world to REASON: what an occasion calls for, what
        goes with what, what suits the weather. That is a different thing from naming a product, and
        it is allowed. What is not allowed is naming something the shop has not shown you — every
        product you mention must have been returned by a tool in this conversation. So think about
        what the shopper actually needs, then look it up before you name anything.
```

Then extend the class docblock: the rules block is the model-facing half of the grounding contract,
and this clause is the one place it says what the contract does NOT forbid — recorded because a rule
that over-reaches is as much a defect as one that under-reaches, and this one over-reached into
refusing to give advice.

- [ ] **Step 4: Run; check nothing else moved**

Run: `vendor/bin/phpunit tests/Core/Prompt --testdox` → PASS.
Run: `vendor/bin/phpunit --exclude-group eval` → PASS. Any test asserting the prompt's exact length or
hash will fail; update it to the new value rather than reverting the clause.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Prompt tests/Core/Prompt
git commit -m "fix(prompt): thinking about an occasion is not recommending from memory"
```

### Task 10: The three behaviour assertions

**Files:**
- Create: `src/Eval/Assertion/QuestionsAtMost.php`
- Create: `src/Eval/Assertion/QuestionNamesAReturnedCategory.php`
- Create: `src/Eval/Assertion/RenderedIdsFromEach.php`
- Modify: `src/Eval/Assertion/AssertionRegistry.php`
- Test: `tests/Eval/Assertion/QuestionsAtMostTest.php`, `…/QuestionNamesAReturnedCategoryTest.php`,
  `…/RenderedIdsFromEachTest.php`

**Interfaces:**
- Consumes: `Assertion`, `AssertionResult`, `TraceEvents::payloads()`, the `categories.browse` payload
  from Task 8.
- Produces three journey-file names: `questions_at_most` (`{max: int}`),
  `question_names_a_returned_category` (`{}`), `rendered_ids_from_each` (`{groups: list<list<string>>}`).

All three return `false` from `isSafety()`. Reason, and it goes in each docblock: a turn that asks one
question too many is *worse*, not *unsafe*, and demanding three of three on a stochastic model turns
ordinary variance into a report that the assistant is unsafe — which is the argument
`ToolCallsAtMost::isSafety()` already makes and this follows.

- [ ] **Step 1: Write the failing tests**

`tests/Eval/Assertion/QuestionsAtMostTest.php` — the interesting cases are the ones that decide what
"a question" is:

```php
    public function testItCountsQuestionSentencesNotQuestionMarks(): void
    {
        // One question, two marks. A model writing "Menswear or womenswear? Or both?" has asked one
        // thing; counting marks would call it two and fail a turn that behaved correctly.
        $result = $this->evaluate('Here are some options. Menswear or womenswear? Or both?', max: 1);

        self::assertTrue($result->passed);
    }

    public function testItFailsTwoDistinctQuestions(): void
    public function testMaxZeroPassesProseWithNoQuestion(): void
    public function testMaxZeroFailsAnyQuestion(): void
    public function testAMissingMaxFailsRatherThanPassingVacuously(): void
```

That first case rules out the naive implementation. Two adjacent question sentences where the second
is a continuation of the first (`Or both?`, `Or is it for you?`) count as one; two questions about
different things count as two. Implement by splitting on sentence boundaries and then merging a
question sentence into its predecessor when it opens with a coordinating conjunction (`or`, `and`) —
and write that rule in the docblock, because it is a judgement the class is making and a reader will
otherwise think it is a bug.

`testAMissingMaxFailsRatherThanPassingVacuously` mirrors `ToolCallsAtMost`'s own handling of a missing
`limit`: an expectation nothing reads reports green about nothing.

`QuestionNamesAReturnedCategoryTest` — three cases: prose mentioning one of the names in a
`categories.browse` payload passes; prose mentioning none fails and the message lists what was
available; **no `categories.browse` event at all fails with "the tool was never called"**, which is the
case that distinguishes "asked a grounded question" from "asked a question".

`RenderedIdsFromEachTest` — a turn whose cards include `fw-occ-dress-1` and `fw-occ-suit-3` passes
`groups: [['fw-occ-dress-'], ['fw-occ-suit-']]`; a turn with only dresses fails and the message names
the group that had nothing; a malformed `groups` fails rather than passing.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Eval/Assertion --testdox`
Expected: three classes not found.

- [ ] **Step 3: Write the three assertions**

Each follows `ToolCallsAtMost`'s shape exactly: `name()`, `evaluate()` reading its own expectations
block and returning an `AssertionResult` whose message names the offending content rather than only
the verdict, and `isSafety(): false`.

`QuestionsAtMost::evaluate()` reads `$expectations['max']`, counts with the merging rule above, and
reports `"%d question(s), at most %d allowed: %s"` listing them — a failure has to show the question
so a reader can judge whether the assistant was wrong or the rule is.

`QuestionNamesAReturnedCategory::evaluate()` collects every `names` list from
`TraceEvents::payloads($trace, 'categories.browse')`, flattens it, and passes when
`str_contains(strtolower($turn->prose), strtolower($name))` for any of them.

`RenderedIdsFromEach::evaluate()` reads `$expectations['groups']`, validates it is a non-empty list of
non-empty lists of strings, and requires each group to have at least one `$turn->cards` id starting
with one of its prefixes.

- [ ] **Step 4: Register them**

Three arms in `AssertionRegistry::resolve()`'s `match`, in the order the class list is written:

```php
            'questions_at_most' => new QuestionsAtMost(),
            'question_names_a_returned_category' => new QuestionNamesAReturnedCategory(),
            'rendered_ids_from_each' => new RenderedIdsFromEach(),
```

- [ ] **Step 5: Run and commit**

Run: `vendor/bin/phpunit tests/Eval --exclude-group eval --testdox` → PASS.
Run: `composer run quality` → exit 0. Watch the per-class complexity budget on `QuestionsAtMost` —
the merging rule plus the validation is close to it; split the sentence splitting into its own class
if it goes over, as `Guard`/`VariantSelectionGuard` did.

```bash
git add src/Eval/Assertion tests/Eval/Assertion
git commit -m "test(eval): assert whether the assistant asked, and whether it needed to"
```

### Task 11: The journeys that hold the behaviour, and the outcome report

**Files:**
- Modify: `tests/Journeys/fashion_wedding_occasion.php`, `fashion_undivided_occasion.php`,
  `fashion_false_friend.php`
- Create: `tests/Journeys/fashion_gender_split.php`
- Create: `docs/superpowers/reports/2026-08-26-occasion-queries-outcome.md`
- Modify: `ARCHITECTURE.md`, `docs/extending.md`, `README.md`

**Interfaces:** consumes everything above. Produces the report and the docs.

- [ ] **Step 1: Add the behaviour assertions**

`fashion_wedding_occasion.php` gains:

```php
        'questions_at_most' => ['max' => 1],
        'question_names_a_returned_category' => [],
        'rendered_ids_from_each' => ['groups' => [['fw-occ-dress-'], ['fw-occ-suit-']]],
```

`fashion_undivided_occasion.php` gains the negative control, and nothing else:

```php
        // Trap `fw-undivided`. Yoga wear lives under exactly one department, so nothing the shopper
        // could tell us changes the answer and asking buys only friction. This is the assertion that
        // makes the whole suite mean something: every other one here passes on a model that asks
        // about everything.
        'questions_at_most' => ['max' => 0],
```

`fashion_gender_split.php` is new: the same trap as the wedding journey reached by a phrasing that
names the occasion without naming a department, e.g. `'expert' => 'I have a black-tie event next
month'` / `'beginner' => 'need something smart for a fancy party'`, carrying
`questions_at_most: {max: 1}`, `question_names_a_returned_category`, `rendered_ids_from_each` and the
three grounding assertions. It exists because the wedding journey's word ("wedding") is also the
false-friend's word, and this one is the clean case: an occasion the catalogue splits on, with no
keyword to trip over.

`fashion_false_friend.php` gains `questions_at_most: {max: 1}` only — its point is what the assistant
does with a useless match, and `rendered_ids_from_each` would be asserting a recommendation quality
that this journey is not about.

All four keep `'catalog' => 'fashion'`.

- [ ] **Step 2: Run the fashion journeys**

```bash
ASSISTANT_EVAL_CATALOG=fashion vendor/bin/phpunit --group eval --filter 'fashion_' --testdox
```

Record every assertion's numerator per archetype. **Red here is the expected first result, not a
setback** — the point of Task 4's baseline is that this comparison is now meaningful.

- [ ] **Step 3: Run everything, on both catalogues**

```bash
vendor/bin/phpunit --group eval --testdox
ASSISTANT_EVAL_CATALOG=large vendor/bin/phpunit --group eval --testdox
ASSISTANT_EVAL_CATALOG=fashion vendor/bin/phpunit --group eval --testdox
```

The first two are the regression check: this work changed `SystemPrompt`, `SearchProductsTool` and the
toolbox, and every one of the fifteen existing journeys can see all three. Compare against the phase A
baseline report, not against memory.

- [ ] **Step 4: Re-drive the seeded shop**

The same six phrasings as Task 4 Step 4, same command, same recordings — plus the `categories.browse`
events, and the two query timings Task 6 Step 7 needs.

- [ ] **Step 5: Write the outcome report**

`docs/superpowers/reports/2026-08-26-occasion-queries-outcome.md`, against Task 4's baseline
side by side, one section per trap. It must answer:

1. Per trap, per catalogue: what changed, with the prose quoted both before and after.
2. Did the assistant ask, and was the question grounded in a category the tool returned?
3. On `fw-undivided`: did it correctly ask nothing? **If it asked anyway, say so plainly** — that is
   the finding this whole design turns on, and the honest conclusion may be that the tree cannot carry
   the decision and Approach 1 or 2 from the design conversation is what should have been built.
4. `MAX_NODES` and the two DAL query timings, measured (spec O7).
5. Whether the fifteen existing journeys are unchanged on all three catalogues.

- [ ] **Step 6: Update the three documents that describe the seam**

- `ARCHITECTURE.md`: add `CategoryTreeReader` to the optional-capability list beside
  `BatchProductLookup` and `FamilyVariantLookup`, add `browse_categories` to the tool table and the
  lifecycle description, and add `chosenCategoryIds` where `ProductQuery` is described. **Also correct
  line 465** — the blocklist's two-layer claim, which the spec's *Side finding* established is one
  layer in production for categories. It is a one-line documentation fix and this is the change that
  read the code.
- `docs/extending.md`: a row for "expose a category tree" → implement `CategoryTreeReader` on your
  gateway; note that the tool and its behaviour rule appear automatically and disappear automatically.
- `README.md`: the tool list, and one sentence on what an occasion query now does.

- [ ] **Step 7: Final gate and commit**

Run: `composer run quality` → exit 0.
Run: `vendor/bin/phpunit --exclude-group eval` → PASS.

```bash
git add tests/Journeys docs ARCHITECTURE.md README.md
git commit -m "docs: what an occasion query does now, measured"
```

---

## Self-review

Run against the spec on 2026-08-26, after the plan was written.

**Spec coverage.**

| Spec item | Task |
|---|---|
| Behaviour 1 — ask only when it changes the answer | 8 (rule), 10–11 (asserted both ways) |
| Behaviour 2 — one representative per branch | 7 (list field), 10 (`rendered_ids_from_each`), 11 |
| Behaviour 3 — never more than one question | 10 (`questions_at_most`), 11 |
| O1 discriminator from the tool, not the reply | 5, 8 — no `discriminators` field is added anywhere |
| O2 optional interface beside the seam | 5 |
| O3 measure against both | 3, 4 |
| O4 tool registered only when readable | 8 |
| O5 opaque ids, name, path | 5 |
| O6 no figures, asserted on the encoded reply | 8 Step 1 |
| O7 bounds measured, not chosen | 6 Step 7 |
| O8 never-relaxed field, list of ids | 7 |
| O9 reasoning versus naming | 9 |
| O10 twelve real products verbatim | 1 |
| O11 one generator, two sinks | 1, 3 |
| O12 ~15,000 units, ~1,000 categories | 1 |
| O13 four traps, one a negative control | 1, 11 |
| O14 gate before production code | the GATE section |
| O15 no shopper profile | nothing added — history is what carries the answer |
| O16 no CMS content | nothing added |
| O17 one per branch on an asking turn | 7, 10, 11 |
| O18 "materially different" computable from the tree | 8 — the rule keys off `has_products` and the tree's shape; the criterion is written into the tool description, not a score |
| §Evals four rows | 10 — three classes, four rows (see *Two corrections*) |
| Side finding — blocklist's second layer | 11 Step 6, as a documentation correction |
| Known gap — does the reader honour scope? | **Closed:** it does, both halves, asserted in 5 Step 7 and 6 Step 2 |

**Type consistency.** `CategoryNode`'s five properties are used identically in Tasks 5, 6 and 8.
`chosenCategoryIds` is spelled the same in `ProductQuery`, both gateways, both retries and the trace
payload. The trace stage is `categories.browse` in Tasks 8 and 10, and its `names` key is what Task 10
reads.

**Two corrections made during this review, applied to the tasks above rather than left here:**

1. **Task 7 Step 7 must not add a method to `Guard`.** That class's own docblock records that
   `VariantSelectionGuard` was split out because a fourth method pushed its summed complexity over the
   threshold — so `boundedStringList()` would repeat the exact mistake the comment warns about. The
   category-id bound goes in its own `src/Core/Tool/CategoryIdGuard.php`, with
   `CategoryIdGuard::fromRaw(?array $value, int $maxItems, string $name): list<string>` and a docblock
   pointing at `VariantSelectionGuard` as its precedent.
2. **Task 6 Step 7 runs after Task 8**, because the measurement it takes reads a trace event Task 8
   introduces. It is called out in the step itself; do not reorder the tasks to fix it, because Task 6
   has to be complete and green before Task 8 has a reader to construct.

**Known gap this plan accepts.** `browse_categories` does not tell the model when a level was
truncated at `MAX_NODES`, so a shop with more than that many children under one node is described as
though it had exactly that many. Every other bounded surface in this kit discloses its own trimming —
`CatalogVocabulary`'s "this list is shortened", `TruncatedFamilies`, `SearchProductsTool`'s `more`
flag — and this one should too. It is left out of v1 because the bound is not yet measured (O7), and a
disclosure keyed to a guessed bound is worse than none. Task 6 Step 7 produces the number; adding the
flag afterwards is a small, separate change, and it belongs in the outcome report's own list of gaps.
