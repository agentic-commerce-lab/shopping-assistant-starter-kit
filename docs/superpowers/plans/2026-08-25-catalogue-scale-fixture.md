# Catalogue-Scale Robustness, Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make it possible to run this kit's existing eval suite against a catalogue two orders of magnitude larger than the one it was built on, add four assertions aimed at the constants that scale crosses, and write down what currently happens.

**Architecture:** One seeded, pure generator produces a large catalogue fixture — the 12 existing products verbatim, plus ~2,000 generated units, plus four named traps — into gitignored `var/`. The eval harness picks its fixture from an environment variable, default unchanged. Four new journeys assert one trap each. No production code changes, which is what makes the measurement usable as a baseline.

**Tech Stack:** PHP 8.2, PHPUnit 11, Mago (format + lint + analyze), the existing `Swag\AssistantStarterKit\Tests\` autoload-dev namespace.

**Spec:** `docs/superpowers/specs/2026-08-25-catalogue-scale-robustness-design.md` — read it first. Decisions S3 (superset) and S7 (tune nothing) are the two a later reader is most likely to "improve" and the two that would cost the most.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- PHP 8.2 target. Cyclomatic complexity ≤ 10, nesting depth ≤ 4, ≤ 5 parameters, ~400 lines per file (`composer run quality:filesize` covers `src` only, but keep test files inside it too).
- No `echo`/`var_dump`/`print_r`/`dd` in application code. Mago's `no-debug-symbols` blocks them. Allowed in scripts and CLIs.
- Throw `Throwable` subclasses only; preserve `$previous` when wrapping.
- **Change no file under `src/`.** Phase A is measurement; S7 forbids tuning, and touching production code would make the baseline describe something other than what ships.
- Run `composer run format` before every commit; the `pre-commit` hook runs `mago fmt --check` and `mago lint` on staged PHP and will reject an unformatted file.
- The deterministic suite is `vendor/bin/phpunit --exclude-group eval`. It must stay green and must not get slower.
- **Known unrelated red:** `tests/Core/Policy/RequestBudgetTest.php` and the `requestsPerMinute` assertions in `tests/Core/Config/SystemConfigAssistantConfigTest.php` may fail from a parallel line of work. Verify against `git stash list` / `git status` before blaming this plan; use `--filter '/^(?!.*RequestBudget).*$/'` to isolate.

## File Structure

| File | Responsibility |
|---|---|
| `tests/Fixtures/Large/LargeCatalogGenerator.php` (create) | Builds the large catalogue as a PHP array. Pure: no filesystem, no randomness at call time, seeded arithmetic only. One public method returning the decoded structure, one returning it as JSON. |
| `tests/Fixtures/Large/ScaleTrap.php` (create) | The four trap ids as constants, so a journey, a test and the generator all name the same string and a typo is a compile-time problem rather than a silent miss. |
| `tests/Fixtures/Large/LargeCatalogFile.php` (create) | The only part that touches disk: writes the generator's JSON to `var/catalog-large.json` if absent or stale, returns the path. Separated so the generator stays testable without a filesystem. |
| `tests/Fixtures/Large/LargeCatalogGeneratorTest.php` (create) | Deterministic assertions: the counts from S4, every trap's shape, byte-identical output for one seed, and that all 12 original products survive verbatim. |
| `tests/Eval/JourneyEvalTest.php:70` (modify) | Chooses the fixture path from `ASSISTANT_EVAL_CATALOG`. Default `small` — unchanged behaviour. |
| `tests/Journeys/scale_family_beyond_window.php` (create) | Trap `sc-family-30`. |
| `tests/Journeys/scale_option_beyond_facet_limit.php` (create) | Trap `sc-rare-option`. |
| `tests/Journeys/scale_deep_duplicate.php` (create) | Trap `sc-deep-duplicate`. |
| `tests/Journeys/scale_broad_term.php` (create) | Trap `sc-broad-term`. |
| `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md` (create) | What the measurement found. The deliverable of Task 5. |

The four journey files are separate rather than one file with four archetypes because
`JourneyEvalTest::journeys()` globs one data set per file, and a red run has to name which trap
failed without reading the archetype list.

---

### Task 1: The trap ids, named once

**Files:**
- Create: `tests/Fixtures/Large/ScaleTrap.php`
- Test: `tests/Fixtures/Large/LargeCatalogGeneratorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ScaleTrap::FAMILY_PARENT` = `'sc-family-30'`, `ScaleTrap::RARE_OPTION_PRODUCT` = `'sc-rare-option'`, `ScaleTrap::DEEP_DUPLICATE` = `'sc-deep-duplicate'`, `ScaleTrap::BROAD_TERM_WORD` = `'Trailmaster'`, `ScaleTrap::RARE_OPTION_VALUE` = `'Chartreuse'`, `ScaleTrap::RARE_OPTION_GROUP` = `'Colour'`, `ScaleTrap::FAMILY_SOLD_OUT_VARIANT` = `'sc-family-30-v30'`, `ScaleTrap::COMMON_NAME` = `'Alloy Water Bottle 750ml'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Fixtures/Large/LargeCatalogGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use PHPUnit\Framework\TestCase;

/**
 * The generator is test infrastructure, and test infrastructure that is wrong produces a green run
 * that means nothing. These assertions are the reason a generated fixture is trustworthy at all —
 * see spec decision S2, which chose a generator over a committed blob precisely so this file could
 * exist.
 */
final class LargeCatalogGeneratorTest extends TestCase
{
    public function testEveryTrapIdIsDistinct(): void
    {
        $ids = [
            ScaleTrap::FAMILY_PARENT,
            ScaleTrap::RARE_OPTION_PRODUCT,
            ScaleTrap::DEEP_DUPLICATE,
            ScaleTrap::FAMILY_SOLD_OUT_VARIANT,
        ];

        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * The prefix is load-bearing: a journey asserting `rendered_ids_exactly` on a generated id must
     * be able to tell at a glance that the id is a trap rather than one of the twelve real products.
     */
    public function testEveryTrapIdIsPrefixed(): void
    {
        foreach ([ScaleTrap::FAMILY_PARENT, ScaleTrap::RARE_OPTION_PRODUCT, ScaleTrap::DEEP_DUPLICATE] as $id) {
            self::assertStringStartsWith('sc-', $id);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Fixtures/Large/LargeCatalogGeneratorTest.php`
Expected: FAIL — `Class "Swag\AssistantStarterKit\Tests\Fixtures\Large\ScaleTrap" not found`.

- [ ] **Step 3: Write the implementation**

Create `tests/Fixtures/Large/ScaleTrap.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * The four engineered defects of the large catalogue, each aimed at one constant this kit sets
 * against seventeen sellable units.
 *
 * They are constants rather than literals because three places name each one — the generator that
 * builds it, the test that checks it was built, and the journey that asserts the assistant handles
 * it — and a typo in any of the three is otherwise a silently passing eval.
 *
 * See the spec's *Traps* table for what each one is aimed at and what a wrong answer looks like.
 */
final class ScaleTrap
{
    /** A 30-variant family: past `SearchProductsTool::MIN_CANDIDATES` of 20. */
    public const FAMILY_PARENT = 'sc-family-30';

    /**
     * The sold-out member of that family, generated **last** so retrieval ranking has to reach the
     * end of the window to find it. `SearchProductsTool`'s limit docblock records this exact failure
     * happening once already at `limit: 1`.
     */
    public const FAMILY_SOLD_OUT_VARIANT = 'sc-family-30-v30';

    /** Carries an option value the facet aggregation's 50-bucket cap cannot return. */
    public const RARE_OPTION_PRODUCT = 'sc-rare-option';

    public const RARE_OPTION_GROUP = 'Colour';

    /**
     * Deliberately a value the small fixture already uses in a different sense: ruling notes on
     * `UnmatchedOptionRetry` record `Chartreuse` as the "value in a group the catalogue has, that no
     * product carries" case. Here one product *does* carry it, and the question is whether the probe
     * can see it.
     */
    public const RARE_OPTION_VALUE = 'Chartreuse';

    /** A near-duplicate of `COMMON_NAME`, generated late so it sits deep in insertion order. */
    public const DEEP_DUPLICATE = 'sc-deep-duplicate';

    /** The name it duplicates — one of the small fixture's own near-duplicate pair. */
    public const COMMON_NAME = 'Alloy Water Bottle 750ml';

    /** A coined word shared by roughly 500 generated products, so no real word is polluted. */
    public const BROAD_TERM_WORD = 'Trailmaster';

    private function __construct() {}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Fixtures/Large/LargeCatalogGeneratorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
composer run format
git add tests/Fixtures/Large/ScaleTrap.php tests/Fixtures/Large/LargeCatalogGeneratorTest.php
git commit -m "test: name the four scale traps once, so three callers cannot disagree"
```

---

### Task 2: The generator

**Files:**
- Create: `tests/Fixtures/Large/LargeCatalogGenerator.php`
- Modify: `tests/Fixtures/Large/LargeCatalogGeneratorTest.php`

**Interfaces:**
- Consumes: `ScaleTrap::*` from Task 1.
- Produces:
  - `LargeCatalogGenerator::__construct(string $smallCatalogPath, int $seed = 20260825)`
  - `LargeCatalogGenerator::build(): array` — returns `array{products: list<array{id: string, name: string, description: string|null, price: float, stock: int, url: string, categoryPath: list<string>, properties: array<string, list<string>>, variants: list<array{id: string, options: array<string, string>, price: float, stock: int}>}>}`, the exact shape `FixtureCommerceGateway::fromFile()` decodes.
  - `LargeCatalogGenerator::toJson(): string` — pretty-printed, `JSON_THROW_ON_ERROR`.
  - `LargeCatalogGenerator::GENERATED_PRODUCTS` = `500` (products generated per property group family; see the constant's docblock for the arithmetic).

- [ ] **Step 1: Write the failing tests**

Append these methods inside the existing class in
`tests/Fixtures/Large/LargeCatalogGeneratorTest.php`. No import is needed: `LargeCatalogGenerator`
and `ScaleTrap` live in the same namespace as the test.

```php
    private static function generator(): LargeCatalogGenerator
    {
        return new LargeCatalogGenerator(__DIR__ . '/../catalog.json');
    }

    /** @return array{products: list<array<string, mixed>>} */
    private static function built(): array
    {
        return self::generator()->build();
    }

    /** @param array{products: list<array<string, mixed>>} $catalogue */
    private static function product(array $catalogue, string $id): ?array
    {
        foreach ($catalogue['products'] as $product) {
            if ($product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }

    private static function sellableUnits(array $catalogue): int
    {
        $units = 0;

        foreach ($catalogue['products'] as $product) {
            $units += \count($product['variants']) > 0 ? \count($product['variants']) : 1;
        }

        return $units;
    }

    /**
     * Spec decision S3, and the single most important assertion in this file. Every existing journey
     * asserts against these twelve products by id, price and stock; if the generator perturbs one,
     * a red journey at scale means nothing.
     */
    public function testAllTwelveOriginalProductsSurviveVerbatim(): void
    {
        $small = json_decode(
            (string) file_get_contents(__DIR__ . '/../catalog.json'),
            associative: true,
            depth: 512,
            flags: \JSON_THROW_ON_ERROR,
        );
        $large = self::built();

        self::assertCount(12, $small['products']);

        foreach ($small['products'] as $original) {
            self::assertSame(
                $original,
                self::product($large, $original['id']),
                \sprintf('product %s changed', $original['id']),
            );
        }
    }

    /** Spec decision S4: the point is to cross every constant, so the counts are the contract. */
    public function testItCrossesEveryConstantThisKitSets(): void
    {
        $large = self::built();

        // 2,149 by the arithmetic in LargeCatalogGenerator::GENERATED_PRODUCTS. Asserted as a floor
        // rather than an equality so adding a trap later does not fail this test for the right
        // reason — but a *drop* below it means the filler stopped generating families, which is the
        // way this fixture would quietly stop testing anything.
        self::assertGreaterThan(1_900, self::sellableUnits($large));

        $groups = [];
        $values = [];
        foreach ($large['products'] as $product) {
            foreach ($product['properties'] as $group => $groupValues) {
                $groups[$group] = true;
                foreach ($groupValues as $value) {
                    $values[$group . '|' . $value] = true;
                }
            }
        }

        // Past MAX_FIELDS (30).
        self::assertGreaterThan(55, \count($groups));
        // Past FACET_VALUE_LIMIT (50) and MAX_VALUES_PER_FIELD (25).
        self::assertGreaterThan(350, \count($values));
    }

    public function testTheThirtyVariantFamilyEndsWithTheSoldOutUnit(): void
    {
        $family = self::product(self::built(), ScaleTrap::FAMILY_PARENT);

        self::assertNotNull($family);
        self::assertCount(30, $family['variants']);

        $last = $family['variants'][29];
        self::assertSame(ScaleTrap::FAMILY_SOLD_OUT_VARIANT, $last['id']);
        self::assertSame(0, $last['stock'], 'the trap is that the sold-out unit is ranked last');

        // Every sibling is in stock, so an answer about the sold-out one cannot be right by accident.
        foreach (\array_slice($family['variants'], 0, 29) as $sibling) {
            self::assertGreaterThan(0, $sibling['stock']);
        }
    }

    public function testTheRareOptionValueSitsBeyondTheFacetBucketCap(): void
    {
        $large = self::built();
        $product = self::product($large, ScaleTrap::RARE_OPTION_PRODUCT);

        self::assertNotNull($product);
        self::assertContains(
            ScaleTrap::RARE_OPTION_VALUE,
            $product['properties'][ScaleTrap::RARE_OPTION_GROUP],
        );

        // More than 50 distinct values in that group, so a 50-bucket aggregation cannot return all.
        $inGroup = [];
        foreach ($large['products'] as $candidate) {
            foreach ($candidate['properties'][ScaleTrap::RARE_OPTION_GROUP] ?? [] as $value) {
                $inGroup[$value] = true;
            }
        }

        self::assertGreaterThan(50, \count($inGroup));
    }

    public function testTheDeepDuplicateSharesACommonNameAndComesLate(): void
    {
        $large = self::built();
        $ids = array_map(static fn(array $p): string => $p['id'], $large['products']);

        $duplicate = self::product($large, ScaleTrap::DEEP_DUPLICATE);
        self::assertNotNull($duplicate);
        self::assertSame(ScaleTrap::COMMON_NAME, $duplicate['name']);

        $position = array_search(ScaleTrap::DEEP_DUPLICATE, $ids, strict: true);
        self::assertIsInt($position);
        self::assertGreaterThan(400, $position, 'the trap is that it sits deep in insertion order');
    }

    public function testTheBroadTermIsSharedByHundredsOfProducts(): void
    {
        $matches = 0;

        foreach (self::built()['products'] as $product) {
            if (str_contains($product['name'], ScaleTrap::BROAD_TERM_WORD)) {
                ++$matches;
            }
        }

        self::assertGreaterThan(400, $matches);
    }

    /** Spec decision S2: determinism is what a generated fixture has instead of being committed. */
    public function testTheSameSeedProducesByteIdenticalOutput(): void
    {
        $first = new LargeCatalogGenerator(__DIR__ . '/../catalog.json', seed: 4242);
        $second = new LargeCatalogGenerator(__DIR__ . '/../catalog.json', seed: 4242);

        self::assertSame($first->toJson(), $second->toJson());
    }

    public function testADifferentSeedProducesDifferentOutput(): void
    {
        $a = new LargeCatalogGenerator(__DIR__ . '/../catalog.json', seed: 1);
        $b = new LargeCatalogGenerator(__DIR__ . '/../catalog.json', seed: 2);

        self::assertNotSame($a->toJson(), $b->toJson());
    }

    /** The gateway is the real consumer; if it cannot read the output, nothing else matters. */
    public function testTheFixtureGatewayCanReadTheResult(): void
    {
        $path = sys_get_temp_dir() . '/swag-assistant-scale-test-' . getmypid() . '.json';
        file_put_contents($path, self::generator()->toJson());

        try {
            $gateway = \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway::fromFile($path);
            $card = $gateway->product(
                ScaleTrap::FAMILY_SOLD_OUT_VARIANT,
                new \Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope(),
            );

            self::assertNotNull($card);
            self::assertSame(0, $card->stock);
        } finally {
            @unlink($path);
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Fixtures/Large/LargeCatalogGeneratorTest.php`
Expected: FAIL — `Class "…\LargeCatalogGenerator" not found`.

- [ ] **Step 3: Write the implementation**

Create `tests/Fixtures/Large/LargeCatalogGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * Builds a catalogue two orders of magnitude larger than the one every eval runs against, so the
 * constants this kit sets against seventeen sellable units can be observed above them.
 *
 * **Seeded arithmetic, not `random_int()`.** A fixture that differs between runs turns a red eval
 * into a coin toss, and spec decision S2 chose a generator over a committed blob only because a
 * generator can be deterministic *and* reviewable. The sequence below is a linear congruential
 * generator with the constants glibc uses — not because its statistical quality matters here (it
 * does not; nothing is being sampled) but because it is four lines, needs no extension, and gives
 * the same numbers on every platform and PHP version. `mt_srand()` would do the job on one machine
 * and quietly not on another.
 *
 * **The twelve real products are copied verbatim** (S3). Every existing journey asserts against
 * their ids, prices and stock; perturbing one would make a red journey at scale unreadable. They are
 * emitted first, so their insertion order is unchanged too.
 */
final class LargeCatalogGenerator
{
    /**
     * How many products carry the broad term, and the bulk of the volume.
     *
     * The arithmetic, because the unit count is asserted and a guess would fail the test: 500
     * products, of which every fifth has no variants (100 products, 100 units) and the rest have
     * five (400 × 5 = 2,000 units). With the thirty-variant family, the two single-unit traps and
     * the twelve real products' seventeen units, that is **2,149 sellable units** — past
     * `MIN_CANDIDATES` (20) and `MAX_CANDIDATES` (50) by enough that no window can hold a meaningful
     * fraction of a broad result.
     *
     * Keeping a fifth of them variant-free is deliberate: `StockSource::Product` and
     * `StockSource::Variant` both need to occur at scale, and a catalogue of nothing but families
     * would not exercise the distinction that cost every simple product its add-to-cart button once.
     */
    public const GENERATED_PRODUCTS = 500;

    /** Sizes for the four fifths of filler products that get a family. */
    private const FILLER_SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    /** Past `CatalogVocabularyBudget::MAX_FIELDS` (30). */
    private const GENERATED_GROUPS = 56;

    /** Past `DalCommerceGateway::FACET_VALUE_LIMIT` (50) inside one group. */
    private const COLOUR_VALUES = 60;

    private int $state;

    public function __construct(
        private readonly string $smallCatalogPath,
        int $seed = 20260825,
    ) {
        $this->state = $seed;
    }

    /**
     * @return array{products: list<array{
     *     id: string, name: string, description: string|null, price: float, stock: int, url: string,
     *     categoryPath: list<string>, properties: array<string, list<string>>,
     *     variants: list<array{id: string, options: array<string, string>, price: float, stock: int}>,
     * }>}
     */
    public function build(): array
    {
        $products = $this->smallCatalogue();

        for ($i = 1; $i <= self::GENERATED_PRODUCTS; ++$i) {
            $products[] = $this->filler($i);
        }

        $products[] = $this->thirtyVariantFamily();
        $products[] = $this->rareOptionProduct();
        $products[] = $this->deepDuplicate();

        return ['products' => $products];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->build(),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return list<array<string, mixed>> */
    private function smallCatalogue(): array
    {
        $json = file_get_contents($this->smallCatalogPath);

        if ($json === false) {
            throw new \RuntimeException(\sprintf('Unable to read "%s".', $this->smallCatalogPath));
        }

        /** @var array{products: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($json, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);

        return $decoded['products'];
    }

    /**
     * One ordinary product, and the volume the traps hide in.
     *
     * Every name carries {@see ScaleTrap::BROAD_TERM_WORD} — a coined word, so the broad-term trap
     * cannot be satisfied by a real word that happens to be common. Every fifth product is
     * variant-free and the rest carry five sizes; see {@see self::GENERATED_PRODUCTS} for why, and
     * for the arithmetic the unit-count assertion depends on.
     *
     * @return array<string, mixed>
     */
    private function filler(int $index): array
    {
        $group = 'Attribute ' . ($index % self::GENERATED_GROUPS);
        $id = \sprintf('gen-%04d', $index);
        $name = \sprintf('%s %s %04d', ScaleTrap::BROAD_TERM_WORD, $this->noun($index), $index);
        $price = 5.0 + (float) ($this->next() % 20_000) / 100.0;
        $variants = [];

        if ($index % 5 !== 0) {
            foreach (self::FILLER_SIZES as $size) {
                $variants[] = [
                    'id' => \sprintf('%s-%s', $id, strtolower($size)),
                    'options' => ['Size' => $size],
                    'price' => $price,
                    'stock' => 1 + (int) ($this->next() % 40),
                ];
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'description' => \sprintf('Generated filler product %d.', $index),
            'price' => $price,
            'stock' => 1 + (int) ($this->next() % 40),
            'url' => '/detail/' . $id,
            'categoryPath' => ['Generated', $this->noun($index)],
            'properties' => [
                $group => ['Value ' . ($index % 7)],
                'Colour' => ['Shade ' . ($index % self::COLOUR_VALUES)],
            ],
            'variants' => $variants,
        ];
    }

    /**
     * Trap `sc-family-30`: thirty variants with the sold-out one last.
     *
     * The window is 20–50 candidates, so a family this size cannot fit inside it whole — which
     * `SearchProductsTool`'s own limit docblock calls the property that keeps ranking's in-stock bias
     * from hiding a sold-out unit. Here the sold-out unit is deliberately the last one generated.
     *
     * @return array<string, mixed>
     */
    private function thirtyVariantFamily(): array
    {
        $variants = [];

        for ($i = 1; $i <= 30; ++$i) {
            $variants[] = [
                'id' => \sprintf('%s-v%d', ScaleTrap::FAMILY_PARENT, $i),
                'options' => ['Size' => 'Size ' . $i],
                'price' => 99.0,
                // The thirtieth is the trap. Every sibling is in stock so a correct answer about the
                // sold-out one cannot happen by accident.
                'stock' => $i === 30 ? 0 : 5,
            ];
        }

        return [
            'id' => ScaleTrap::FAMILY_PARENT,
            'name' => 'Endurance Bib Tights',
            'description' => 'Thirty sizes, one of them sold out.',
            'price' => 99.0,
            'stock' => 145,
            'url' => '/detail/' . ScaleTrap::FAMILY_PARENT,
            'categoryPath' => ['Apparel', 'Tights'],
            'properties' => ['Size' => array_map(static fn(int $i): string => 'Size ' . $i, range(1, 30))],
            'variants' => $variants,
        ];
    }

    /**
     * Trap `sc-rare-option`: an option value the facet aggregation's 50-bucket cap cannot return.
     *
     * The filler above spreads 60 `Shade N` values across the `Colour` group; this product adds a
     * 61st value with a real word. Whether `FacetProbe` can see it is the question.
     *
     * @return array<string, mixed>
     */
    private function rareOptionProduct(): array
    {
        return [
            'id' => ScaleTrap::RARE_OPTION_PRODUCT,
            'name' => 'Randonneur Musette',
            'description' => 'One colour, and it is the sixty-first in its group.',
            'price' => 32.0,
            'stock' => 4,
            'url' => '/detail/' . ScaleTrap::RARE_OPTION_PRODUCT,
            'categoryPath' => ['Accessories', 'Bags'],
            'properties' => [ScaleTrap::RARE_OPTION_GROUP => [ScaleTrap::RARE_OPTION_VALUE]],
            'variants' => [],
        ];
    }

    /**
     * Trap `sc-deep-duplicate`: the small fixture's `fx-007` name, on a different product, deep in
     * insertion order.
     *
     * `fx-007` and `fx-008` are already a near-duplicate pair by design. This makes it a trio, with
     * the third one far enough down that reaching it requires the window to be wide enough.
     *
     * @return array<string, mixed>
     */
    private function deepDuplicate(): array
    {
        return [
            'id' => ScaleTrap::DEEP_DUPLICATE,
            'name' => ScaleTrap::COMMON_NAME,
            'description' => 'A third bottle with the same name, added late.',
            'price' => 21.5,
            'stock' => 2,
            'url' => '/detail/' . ScaleTrap::DEEP_DUPLICATE,
            'categoryPath' => ['Accessories', 'Bottles'],
            'properties' => [],
            'variants' => [],
        ];
    }

    /** One of eight nouns, so generated names vary without needing a word list. */
    private function noun(int $index): string
    {
        $nouns = ['Jersey', 'Bottle', 'Pump', 'Saddle', 'Grip', 'Light', 'Tyre', 'Cap'];

        return $nouns[$index % \count($nouns)];
    }

    /**
     * The next value in the seeded sequence.
     *
     * A linear congruential generator with glibc's constants. Deterministic across platforms and PHP
     * versions, which `mt_rand()` is not guaranteed to be — and cross-platform determinism is the
     * whole reason this class exists rather than a committed JSON blob.
     */
    private function next(): int
    {
        $this->state = ($this->state * 1_103_515_245 + 12_345) & 0x7FFFFFFF;

        return $this->state;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Fixtures/Large/LargeCatalogGeneratorTest.php`
Expected: PASS (11 tests). If `testItCrossesEveryConstantThisKitSets` fails on the group count,
`GENERATED_GROUPS` is the knob — it must exceed 55 after the modulo spreads it.

- [ ] **Step 5: Check the whole deterministic suite and the gate**

Run: `vendor/bin/phpunit --exclude-group eval --filter '/^(?!.*RequestBudget).*$/'`
Expected: PASS, and no slower than before — the generator runs only in its own test.

Run: `composer run quality`
Expected: exit 0. `quality:filesize` covers `src` only, but keep the generator under 400 lines
anyway.

- [ ] **Step 6: Commit**

```bash
composer run format
git add tests/Fixtures/Large/
git commit -m "test: generate a catalogue that crosses every constant set against 17 units"
```

---

### Task 3: Writing it to disk, and choosing it by environment

**Files:**
- Create: `tests/Fixtures/Large/LargeCatalogFile.php`
- Create: `tests/Fixtures/Large/LargeCatalogFileTest.php`
- Modify: `tests/Eval/JourneyEvalTest.php` (the `JourneyRunner` construction, currently line 70)

**Interfaces:**
- Consumes: `LargeCatalogGenerator` from Task 2.
- Produces:
  - `LargeCatalogFile::path(): string` — absolute path to `var/catalog-large.json`, written if absent or stale.
  - `LargeCatalogFile::chosen(): string` — the fixture path the eval suite should use, read from `ASSISTANT_EVAL_CATALOG`.

- [ ] **Step 1: Write the failing test**

Create `tests/Fixtures/Large/LargeCatalogFileTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use PHPUnit\Framework\TestCase;

/**
 * The one part of this that touches disk, separated from the generator so the generator stays
 * testable without a filesystem — and tested here because "the eval ran against the wrong
 * catalogue" is the failure mode that would waste a ten-minute model run.
 */
final class LargeCatalogFileTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG');
    }

    public function testItDefaultsToTheSmallCatalogue(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG');

        self::assertStringEndsWith('tests/Fixtures/catalog.json', LargeCatalogFile::chosen());
    }

    public function testAnUnknownValueDefaultsToTheSmallCatalogueRatherThanFailing(): void
    {
        // A typo must not silently produce a large run, and must not break a suite that was going
        // to skip anyway for want of credentials.
        putenv('ASSISTANT_EVAL_CATALOG=larg');

        self::assertStringEndsWith('tests/Fixtures/catalog.json', LargeCatalogFile::chosen());
    }

    public function testLargeSelectsTheGeneratedFileAndWritesIt(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG=large');

        $path = LargeCatalogFile::chosen();

        self::assertStringEndsWith('var/catalog-large.json', $path);
        self::assertFileExists($path);

        $decoded = json_decode(
            (string) file_get_contents($path),
            associative: true,
            depth: 512,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('products', $decoded);
        self::assertGreaterThan(500, \count($decoded['products']));
    }

    public function testWritingTwiceProducesTheSameBytes(): void
    {
        $first = file_get_contents(LargeCatalogFile::path());
        @unlink(LargeCatalogFile::path());
        $second = file_get_contents(LargeCatalogFile::path());

        self::assertSame($first, $second);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Fixtures/Large/LargeCatalogFileTest.php`
Expected: FAIL — `Class "…\LargeCatalogFile" not found`.

- [ ] **Step 3: Write the implementation**

Create `tests/Fixtures/Large/LargeCatalogFile.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * Puts the generated catalogue where {@see \Swag\AssistantStarterKit\Eval\JourneyRunner} can read it,
 * and decides which catalogue an eval run should use.
 *
 * `var/` is already gitignored, which is spec decision S2's other half: the generator is the
 * reviewable artefact and the file is a build output. Regenerating it must therefore be free, and it
 * is — the generator is pure arithmetic over a 12-product JSON.
 *
 * **An unknown value falls back to small rather than failing.** A typo in an environment variable is
 * not worth a red suite, and the alternative — a run that silently used the large catalogue because
 * someone wrote `larg` — is the expensive mistake, not the cheap one.
 */
final class LargeCatalogFile
{
    private const ENV = 'ASSISTANT_EVAL_CATALOG';

    private const LARGE = 'large';

    private function __construct() {}

    /** The catalogue an eval run should use, from the environment. */
    public static function chosen(): string
    {
        $requested = getenv(self::ENV);

        if (\is_string($requested) && self::LARGE === strtolower(trim($requested))) {
            return self::path();
        }

        return self::smallPath();
    }

    /** Absolute path to the generated catalogue, written on demand. */
    public static function path(): string
    {
        $path = self::repositoryRoot() . '/var/catalog-large.json';

        if (!is_file($path)) {
            self::write($path);
        }

        return $path;
    }

    public static function smallPath(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/catalog.json';
    }

    private static function write(string $path): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, recursive: true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create "%s".', $directory));
        }

        $json = (new LargeCatalogGenerator(self::smallPath()))->toJson();

        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException(\sprintf('Unable to write "%s".', $path));
        }
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Fixtures/Large/LargeCatalogFileTest.php`
Expected: PASS (4 tests). Then confirm the file landed where it should and is ignored:

Run: `ls -la var/catalog-large.json && git status --porcelain var/`
Expected: the file exists; `git status` shows nothing for `var/`.

- [ ] **Step 5: Point the eval suite at the chosen catalogue**

In `tests/Eval/JourneyEvalTest.php`, replace the `JourneyRunner` construction (currently line 70):

```php
        $runner = new JourneyRunner($settings, __DIR__ . '/../Fixtures/catalog.json');
```

with:

```php
        // Which catalogue this journey runs against. `ASSISTANT_EVAL_CATALOG=large` swaps in the
        // generated one; anything else, including unset, keeps the twelve-product fixture every
        // expectation in tests/Journeys was written against. See spec decision S6 — the large run is
        // opt-in because the suite already costs ten minutes and real money.
        $runner = new JourneyRunner($settings, LargeCatalogFile::chosen());
```

and add the import beside the existing ones:

```php
use Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogFile;
```

- [ ] **Step 6: Verify the default did not change**

Run: `vendor/bin/phpunit --exclude-group eval --filter '/^(?!.*RequestBudget).*$/'`
Expected: PASS, unchanged count plus the four new tests from this task.

Run: `vendor/bin/phpunit --group eval --filter variant_stock`
Expected: PASS — the small catalogue is still the default, so an existing journey behaves exactly as
before. This costs one journey's model time (~30 s) and is the only proof that Step 5 changed nothing
by default.

- [ ] **Step 7: Commit**

```bash
composer run format
git add tests/Fixtures/Large/ tests/Eval/JourneyEvalTest.php
git commit -m "test: let an eval run choose its catalogue, defaulting to the small one"
```

---

### Task 4: Four journeys, one per trap

**Files:**
- Create: `tests/Journeys/scale_family_beyond_window.php`
- Create: `tests/Journeys/scale_option_beyond_facet_limit.php`
- Create: `tests/Journeys/scale_deep_duplicate.php`
- Create: `tests/Journeys/scale_broad_term.php`

**Interfaces:**
- Consumes: the trap ids from Task 1 (as literals — a journey file is a `return [...]` read by
  `Journey::fromFile()` and cannot import a class), and the assertion names registered in
  `Eval\Assertion\AssertionRegistry`: `no_invented_product`, `rendered_ids_exactly`,
  `stock_matches_source`, `no_unbacked_price_in_prose`, `no_absence_claim_in_prose`.
- Produces: four data sets for `JourneyEvalTest::journeys()`.

**Before writing these:** the four journeys only make sense against the large catalogue. Against the
small one their products do not exist, so they would fail for the wrong reason. Verify the
expectations are reachable at the retrieval layer first — Step 1 does that, and it is the step that
saves a wasted model run.

- [ ] **Step 1: Prove the expectations are reachable without a model**

Run this, which drives the real tool chain against the generated catalogue and prints what retrieval
returns:

```bash
ASSISTANT_EVAL_CATALOG=large php -r '
require "vendor/autoload.php";
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\{FactRenderer, VariantResolver};
use Swag\AssistantStarterKit\Core\Policy\{AssistantConfig, BlocklistFilter};
use Swag\AssistantStarterKit\Core\Retrieval\{FacetProbe, QueryBuilder};
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogFile;

$path = LargeCatalogFile::path();
$cases = [
  "family, sold-out size 30" => ["Endurance Bib Tights", [["Size", "Size 30"]]],
  "rare option"              => ["Randonneur Musette", [["Colour", "Chartreuse"]]],
  "deep duplicate"           => ["Alloy Water Bottle 750ml", null],
  "broad term"               => ["Trailmaster", null],
];
foreach ($cases as $label => [$term, $options]) {
    $t = new TraceRecorder();
    $g = FixtureCommerceGateway::fromFile($path);
    $tool = new SearchProductsTool($g, new FacetProbe($g, $t), new QueryBuilder(),
        new VariantResolver($g, $t), new BlocklistFilter(), new FactRenderer($t), $t, new AssistantConfig());
    $r = $tool(term: $term, options: $options);
    printf("%-26s -> %d Treffer: %s%s\n", $label, $r["total"],
        implode(", ", array_map(fn($p) => $p["id"], $r["products"])),
        isset($r["note"]) ? "  [note]" : "");
}'
```

Expected: each case returns something, and the family case returns
`sc-family-30-v30`. **Write down the actual ids** — the journeys below assert on them, and if
retrieval cannot reach a trap then the trap is testing the fixture matcher rather than the product,
which is a finding for Task 5 rather than a journey.

- [ ] **Step 2: Write the four journey files**

`tests/Journeys/scale_family_beyond_window.php`:

```php
<?php

declare(strict_types=1);

// Trap `sc-family-30`. Thirty variants, and the one the shopper asks about is the sold-out
// thirtieth — generated last, so reaching it means the retrieval window held the family whole.
//
// `SearchProductsTool`'s limit docblock records this failure having already happened once: with a
// narrow window, "do you have the blue jersey in M?" returned the blue L that happens to be in
// stock, because ranking's in-stock bias sorts a sold-out unit last. The window is 20–50 candidates;
// this family is 30 variants inside a 2,000-unit catalogue.
//
// REQUIRES `ASSISTANT_EVAL_CATALOG=large`. Against the small catalogue this product does not exist
// and the journey fails for the wrong reason.
return [
    'id' => 'scale_family_beyond_window',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Endurance Bib Tights, Size 30 — in stock?',
        'beginner' => 'do you have those endurance bib tights in size 30?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        // The sold-out unit itself, with its own zero — not a sibling's five.
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['sc-family-30-v30' => 0]],
        'rendered_ids_exactly' => ['expect' => ['sc-family-30-v30']],
        'no_unbacked_price_in_prose' => [],
    ],
];
```

`tests/Journeys/scale_option_beyond_facet_limit.php`:

```php
<?php

declare(strict_types=1);

// Trap `sc-rare-option`. One product carries `Colour: Chartreuse`, the sixty-first value in a group
// whose aggregation is capped at fifty buckets (`DalCommerceGateway::FACET_VALUE_LIMIT`).
//
// The fixture gateway has no such cap, so what this measures at fixture scale is narrower and worth
// stating: whether a **rare** option value still resolves when the vocabulary block handed to the
// model is a truncated sample that almost certainly does not contain it. That is the same shape as
// the `gloves` defect — the model searching with a word the catalogue's advertised vocabulary does
// not list.
//
// REQUIRES `ASSISTANT_EVAL_CATALOG=large`.
return [
    'id' => 'scale_option_beyond_facet_limit',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'Randonneur Musette in Chartreuse — do you have it?',
        'beginner' => 'im after that randonneur musette bag in chartreuse',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'rendered_ids_exactly' => ['expect' => ['sc-rare-option']],
        // The vocabulary being incomplete must not become "the shop does not sell it".
        'no_absence_claim_in_prose' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
```

`tests/Journeys/scale_deep_duplicate.php`:

```php
<?php

declare(strict_types=1);

// Trap `sc-deep-duplicate`. `fx-007` and `fx-008` are already a deliberate near-duplicate pair with
// different stock; this makes it a trio, with the third one generated late so reaching it needs the
// candidate window to be wide enough at 2,000 units.
//
// A shopper asking about "the 750ml bottle" must be shown all three rather than an arbitrary two —
// the same reasoning that made the original pair a trap. Naming two of three is the failure: the
// shopper cannot tell that a choice was made for them.
//
// REQUIRES `ASSISTANT_EVAL_CATALOG=large`.
return [
    'id' => 'scale_deep_duplicate',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'which Alloy Water Bottle 750ml options are there?',
        'beginner' => 'hi, what 750ml alloy bottles do you have?',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'rendered_ids_exactly' => ['expect' => ['fx-007', 'fx-008', 'sc-deep-duplicate']],
        'no_unbacked_price_in_prose' => [],
    ],
];
```

`tests/Journeys/scale_broad_term.php`:

```php
<?php

declare(strict_types=1);

// Trap `sc-broad-term`. Roughly 500 products carry the coined word `Trailmaster`, and
// `SearchProductsTool::MAX_LIMIT` is 8.
//
// The premise the card row rests on is that eight products are a **shortlist**. Against seventeen
// units that premise is free. Against 500 matches it is a claim: eight arbitrary products presented
// as an answer, with nothing saying the other 492 exist. `retrieve.narrow` records the truncation in
// the trace, but the shopper is not reading the trace.
//
// This journey asserts the two things that must hold whatever the shortlist contains: nothing
// invented, and no price the cards do not back. Whether eight-of-500 is an acceptable *answer* is a
// product question for the Task 5 report, not something an assertion can settle — which is why there
// is deliberately no `rendered_ids_exactly` here.
//
// REQUIRES `ASSISTANT_EVAL_CATALOG=large`.
return [
    'id' => 'scale_broad_term',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'what Trailmaster products do you carry?',
        'beginner' => 'show me trailmaster stuff',
    ],
    'config' => [],
    'assertions' => [
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
        'no_absence_claim_in_prose' => [],
    ],
];
```

- [ ] **Step 3: Verify all journeys still parse**

Run:

```bash
php -r 'require "vendor/autoload.php";
foreach (glob("tests/Journeys/*.php") as $f) {
    $j = Swag\AssistantStarterKit\Eval\Journey::fromFile($f);
    printf("%-34s ok  assertions=%d\n", basename($f, ".php"), count($j->assertions));
}'
```

Expected: 19 lines, all `ok`. A typo in an assertion name throws at load time by design — that is
`AssertionRegistry`'s unconditional default arm doing its job.

- [ ] **Step 4: Confirm the small-catalogue default is unaffected**

Run: `vendor/bin/phpunit --exclude-group eval --filter '/^(?!.*RequestBudget).*$/'`
Expected: PASS. The new journey files are data; nothing deterministic reads them.

- [ ] **Step 5: Commit**

```bash
composer run format
git add tests/Journeys/
git commit -m "test: four journeys, one per constant that catalogue scale crosses"
```

---

### Task 5: The measurement, and the report

**Files:**
- Create: `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the document that gates phase B, and the input to the constant-tuning decision S7 defers.

This task has no test. Its deliverable is a measurement, and a measurement that asserted its own
result would not be one.

- [ ] **Step 1: Run the fifteen existing journeys against the large catalogue**

```bash
ASSISTANT_EVAL_CATALOG=large timeout 3000 vendor/bin/phpunit --group eval \
  --filter '/^(?!.*scale_).*$/' 2>&1 | tee /tmp/scale-existing.txt
```

Expected: takes ~10–15 minutes and costs real model calls. Record the pass/fail line for every
journey and every archetype. **Do not fix anything yet** — S7, and a fix invalidates the baseline.

- [ ] **Step 2: Run the four new journeys**

```bash
ASSISTANT_EVAL_CATALOG=large timeout 2400 vendor/bin/phpunit --group eval \
  --filter 'scale_' 2>&1 | tee /tmp/scale-new.txt
```

- [ ] **Step 3: Capture what the model was actually told**

The vocabulary block is the prime suspect, so measure it rather than reasoning about it:

```bash
ASSISTANT_EVAL_CATALOG=large php -r '
require "vendor/autoload.php";
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Prompt\{CatalogVocabulary, SystemPrompt};
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogFile;

foreach (["small" => LargeCatalogFile::smallPath(), "large" => LargeCatalogFile::path()] as $label => $path) {
    $t = new TraceRecorder();
    $g = FixtureCommerceGateway::fromFile($path);
    $facets = (new FacetProbe($g, $t))->probe(new CatalogScope());
    $v = CatalogVocabulary::renderWithStats($facets);
    $prompt = SystemPrompt::build(new AssistantConfig(), $v["text"]);
    printf("%-6s fields=%d values=%d truncated=%s vocabChars=%d promptChars=%d\n",
        $label, $v["fieldCount"], $v["valueCount"], $v["truncated"] ? "YES" : "no",
        strlen($v["text"]), strlen($prompt));
}'
```

Expected: the large row shows `truncated=YES` and a field count far below the catalogue's real one
(the fixture has ~59 property groups; `MAX_FIELDS` is 30 and `MAX_TOTAL_CHARS` is 1500, so the block
cannot carry them).

`renderWithStats()` is the right method — `render()` returns only the string. Its return shape is
`array{text: string, fieldCount: int, valueCount: int, truncated: bool}`.

- [ ] **Step 4: Write the report**

Create `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md` with exactly these sections
and the numbers from Steps 1–3. Every cell must hold a measured value or the word `not measured` —
no estimates, and no conclusions the runs do not support.

```markdown
# Catalogue Scale — Phase A Baseline

**Date:** 2026-08-25
**Catalogue:** `var/catalog-large.json`, generated by `LargeCatalogGenerator` seed 20260825
**Spec:** `docs/superpowers/specs/2026-08-25-catalogue-scale-robustness-design.md`

## What was measured against what

| | Small | Large |
|---|---|---|
| Sellable units | 17 | |
| Property groups | 4 | |
| Option values | 9 | |
| Largest family | 4 | |

## What the model is told

| Catalogue | Vocabulary fields | Values | Truncated | Vocabulary chars | Prompt chars |
|---|---|---|---|---|---|
| small | | | | | |
| large | | | | | |

## The fifteen existing journeys at scale

| Journey | Archetype | Small (known) | Large | Failing assertion |
|---|---|---|---|---|

## The four scale journeys

| Journey | Archetype | Result | Failing assertion |
|---|---|---|---|

## Findings

One numbered finding per real defect. For each: what was observed, the smallest input that shows it,
and whether the cause is the product or the fixture matcher — spec section *What phase A cannot test*
names the second as a candidate that has to be ruled out before the first is blamed.

## What this does not say

Retrieval quality at scale. `FixtureTermMatcher` is substring matching with an any-token fallback,
not Shopware's keyword index — no ranking, no `slop()`, no plural blind spot. Every number above
describes the layers above the gateway.

## Recommended next step

Either a fix plan for the findings, or phase B, with the reason for the order.
```

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md
git commit -m "docs: what the kit does when the catalogue is 100x the one it was built on"
```

---

## Gate

**Phase B is not planned until this report exists.** Its thresholds — what counts as too slow, too
truncated, too wide — are exactly what Step 4 produces, and writing them beforehand would be
inventing the answer the measurement exists to produce.

**Fixes are not in this plan either.** Task 5 may find several; each needs its own failing test
before its own fix, and none of them can be written before the finding. That is the same reason, and
writing "fix whatever breaks" as a step would be the placeholder this plan's own constraints forbid.

## Spec coverage

| Decision | Where |
|---|---|
| S1 two phases, gate between | *Gate* above; phase B deliberately absent |
| S2 generated, seeded, only the generator committed | Task 2 (`next()`, determinism tests), Task 3 (`var/`) |
| S3 strict superset | Task 2, `testAllTwelveOriginalProductsSurviveVerbatim` |
| S4 scale targets | Task 2, `testItCrossesEveryConstantThisKitSets` |
| S5 four named traps | Task 1 (`ScaleTrap`), Task 2 (three builders + the filler's broad term), Task 4 (one journey each) |
| S6 opt-in via `ASSISTANT_EVAL_CATALOG` | Task 3 |
| S7 tune nothing | *Gate*; Global Constraints forbid touching `src/` |
| S8 never seed the staging shop | Phase B only — no task here touches a shop |

## Known risks

1. **A journey may go red because the fixture matcher got broader, not because the product broke.**
   `FixtureTermMatcher`'s any-token fallback matches far more at 2,000 units. Task 5's *Findings*
   section requires each finding to say which of the two it is; a finding that cannot say is not a
   finding yet.
2. **`rendered_ids_exactly` on three ids** (`scale_deep_duplicate`) is the most brittle assertion
   here, because it depends on retrieval order as well as membership. If it fails on ordering rather
   than on content, that is a finding about the assertion, not about the product — and
   `RenderedIdsExactly` should be read before concluding anything.
3. **Cost.** Task 5 spends roughly 19 journeys × 2 archetypes × 3 runs of model time, twice over.
   Budget ~25 minutes of wall clock and the corresponding spend, and do not re-run it casually.
