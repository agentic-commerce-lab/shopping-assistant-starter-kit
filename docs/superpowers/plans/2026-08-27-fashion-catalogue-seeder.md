# Fashion Catalogue Seeder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A dev-only console command that writes the fashion taxonomy — ~15,200 sellable units across
~1,031 category nodes — into a real Shopware shop through the DAL, so the fashion evals and the
occasion-query behaviour can finally be measured against real Shopware search instead of the in-memory
fixture that has produced every fashion-scale number so far.

**Architecture:** Pure, fully unit-tested "plan" classes build the whole category tree, property-group
set and product list as plain PHP arrays first — deterministic ids derived from a stable hash, no
Shopware dependency, no database. A thin `SeedRunner` then hands those arrays to `SeedWriter`, which is
the only code in this feature that talks to a Shopware `EntityRepository`, batches the writes, and
toggles indexing exactly the way `Shopware\Core\Framework\Demodata\Generator\{ProductGenerator,
CategoryGenerator}` already do. A `SeedGuard`, built on a two-method interface rather than a concrete
repository, decides whether the shop has already been seeded — its branching logic is the one
DAL-adjacent piece worth a real unit test, via a fake implementing that interface.

**Tech Stack:** PHP 8.2, PHPUnit 11, Symfony Console, Shopware 6 DAL (`EntityRepository`), run inside the
`shopping-assistant-test` Docker shop this repo is bind-mounted into.

**Spec:** `docs/superpowers/specs/2026-08-26-occasion-queries-at-fashion-scale-design.md` (superseded in
places — see `HANDOFF.md`'s "What the spec got wrong" table) and `HANDOFF.md` itself, section "Task A —
seed the fashion catalogue into the real shop", which is the actual brief this plan implements.

## Context — the two divergences this exists to close

Measured and written up in `docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md` and
`docs/superpowers/reports/2026-08-27-end-to-end-on-the-real-shop.md`:

1. `FixtureFacetBuilder` emits a `categoryPath` Terms facet, so the fixture's vocabulary block contains
   category names outright. `DalCommerceGateway::facets()` registers price, `properties`, `options` and
   `manufacturer.name` — **no category aggregation at all**. Every fashion-scale answer measured so far
   had help production does not give it.
2. `FixtureTermMatcher` has no stemming, so `Occasion Suits` fails its all-token pass and the any-token
   pass wrongly returns the dresses — a fixture-matcher artefact, not a retrieval defect, and it can only
   be told apart from a real defect by running the same query through real Shopware search.

Until a real shop carries this catalogue, every number in both reports is a fixture number.

## What already exists and this plan reuses without changing

Read before Task 1 — this plan does not re-derive any of it:

- `tests/Fixtures/Fashion/FashionTaxonomy.php` — the category shape: `DEPARTMENTS` (3) ×
  `GARMENT_TYPES` (14) × `CUTS` (22) = 924 leaves per department = 969 garment-tree nodes including the
  3 department and 42 type nodes; plus a `Brand` (40 leaves) / `Season` (4) / `Occasion` (10) branch = 57
  nodes including 3 parents. `garmentLeaf(int $index)` and `sideLeaf(int $index)` turn an index into a
  `{path, name}` pair by pure arithmetic — no randomness in placement.
- `tests/Fixtures/Fashion/FashionTrapProducts.php` — the four named traps: six occasion dresses under
  `Women > Occasion & Party > Occasion Dresses`, six occasion suits under
  `Men > Suits & Tailoring > Occasion Suits`, four yoga pieces under `Women > Activewear > Yoga`, and one
  `Wedding Cake Topper Charm` under `Gifts & Novelty > Keepsakes` — the only product in the catalogue
  whose name contains "wedding".
- `src/Command/ProbeCommand.php` and `src/Command/BenchmarkCommand.php` — the two precedents for a
  console command that needs a `SalesChannelContext` outside an HTTP request: build one with
  `AbstractSalesChannelContextFactory::create()`, then scope work to it via
  `SalesChannelContextProvider::use()`.
- `vendor/shopware/core/Framework/Demodata/Generator/{ProductGenerator,CategoryGenerator,
  PropertyGroupGenerator}.php` (read from the shop's own vendor tree —
  `~/Workspace/shopping-assistant-test/vendor/…`, not this repo, which carries no `vendor/shopware`) —
  the only verified-correct minimal write payloads for these three entities, and the
  `EntityIndexerRegistry::DISABLE_INDEXING` state toggle that makes a bulk write affordable.

## The category-node arithmetic this plan seeds (measured, not estimated)

The fixture's `CATEGORY_NODES = 1_043` includes 12 nodes contributed by the twelve real `fx-*` products
copied in verbatim (O10) — this seeder does not touch those; they already exist in the real shop as its
existing 135 products. Excluding them:

| Branch | Nodes | Arithmetic |
|---|---|---|
| Garment tree | 969 | 3 departments + (3×14) type nodes + (3×14×22) cut leaves |
| Brand / Season / Occasion | 57 | 3 parents + 40 + 4 + 10 leaves |
| Trap-only leaves | 5 | `Occasion Dresses`, `Occasion Suits`, `Yoga` (new leaves under existing type nodes) + `Gifts & Novelty` + `Keepsakes` (a wholly new branch) |
| **Total** | **1,031** | |

Sellable units, same exclusion (the fixture's 17 units from the twelve real products are not reseeded):

| Source | Products | Sellable units | Arithmetic |
|---|---|---|---|
| Generated filler | 3,600 | 15,120 | every 5th of 3,600 is variant-free (720×1); the rest carry 5 sizes (2,880×5) |
| Traps | 17 | 81 | (6+6+4)×5 sized variants = 80, +1 variant-free false friend |
| **Total** | **3,617** | **15,201** | |

Both totals are asserted as class constants in Task 1/2/6, the same way
`FashionCatalogGenerator::CATEGORY_NODES` and `::SELLABLE_UNITS` are — measured by running the test, not
computed by hand a second time and trusted.

## Global Constraints

- PHP **8.2+**, `declare(strict_types=1)` everywhere.
- `composer run quality` must exit **0**: `mago fmt --check`, `mago lint`, `mago analyze`
  (`cyclomatic-complexity` threshold **10** per method), `check_file_length.php` (**400** physical lines
  per file), `jscpd`, `composer-dependency-analyser`, `composer audit`. `excessive-parameter-list`
  threshold is 5; `too-many-methods` fires past ~15.
- **This repo does have real `shopware/core` in `vendor/`** (`composer.json` requires `~6.7.0`, not a
  stub) — `Context`, `Uuid`, `Defaults` and every other Shopware-core class resolve normally under this
  repo's own `vendor/bin/phpunit` and `mago analyze`. What this repo's `tests/bootstrap.php` does **not**
  do is boot a Shopware kernel or database connection: no `KernelTestBehaviour` /
  `IntegrationTestBehaviour` wiring exists (verified — those traits are present in `vendor/` but nothing
  here uses them), so a real `EntityRepository`, `SalesChannelContext`, or `Doctrine\DBAL\Connection`
  cannot be constructed inside this repo's test suite even though the classes themselves are visible to
  it. **That** is the actual boundary — not a missing dependency — and it is exactly why Tasks 1–6 below
  are pure PHP built only from plain value objects and constants (unit tested here), while Tasks 7–8
  (which need a real `EntityRepository`/`SalesChannelContext`) are verified by hand inside the Docker
  container (Task 10) rather than by this repo's test suite. `plugin-src` inside
  `~/Workspace/shopping-assistant-test` is a bind mount of this repo (`compose.override.yaml`) — there is
  no deploy step, `git switch` here changes what the shop runs.
- **`tests/` is not autoloaded inside a running Shopware installation.** Anything the console command
  needs at runtime must live under `src/`, never `tests/`. Tasks 1–2 duplicate the two `tests/Fixtures/
  Fashion` classes' word lists into `src/Command/Seed/`, each backed by a parity test asserting the
  duplication is exact — per `HANDOFF.md`: *"the two catalogues genuinely differ and Task A measures a
  different shop"* is the failure this guards against.
- **No `--force` option, anywhere.** `HANDOFF.md` is explicit: *"the recovery for 'I seeded twice' is a
  database restore either way."* The guard (Task 7) is the only defence and it has no override.
- Every id this feature creates (`SeedId`, Task 3) is a deterministic hash of a stable string, never
  `Uuid::randomHex()`. Same reasoning `FashionCatalogGenerator`'s docblock already gives for its LCG:
  *"a fixture that differs between runs turns a red eval into a coin toss"* — here it also means a
  second, guard-bypassed run would attempt to recreate identical ids rather than silently doubling the
  catalogue.
- `docs/superpowers/reports/2026-08-27-end-to-end-on-the-real-shop.md`: `APP_ENV=prod` when seeding —
  `dev` runs the profiler and inflates every number, and would make writing 3,617 products distinctly
  slower. The command warns rather than blocks on this (Task 8) — it is a measurement-quality concern,
  not a correctness one, and this plan adds no new `--force`-shaped override.

---

### Task 1: Duplicate the taxonomy word lists

**Files:**
- Create: `src/Command/Seed/FashionSeedTaxonomy.php`
- Test: `tests/Command/Seed/FashionSeedTaxonomyParityTest.php`

**Interfaces:**
- Produces: `FashionSeedTaxonomy::DEPARTMENTS`, `::GARMENT_TYPES`, `::GARMENT_SINGULARS`,
  `::GARMENT_PLURALS`, `::CUTS`, `::BRANDS`, `::SEASONS`, `::OCCASIONS` — byte-identical to
  `Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTaxonomy`'s same-named constants.
  `FashionSeedTaxonomy::leafCount(): int`, `::sideLeafCount(): int`,
  `::garmentLeaf(int $index): array{path: list<string>, name: string}`,
  `::sideLeaf(int $index): array{path: list<string>, name: string}` — identical output to the fixture
  class for every valid index, verified by the parity test rather than assumed from identical source.
- Consumed by: Task 4 (`CategoryTreePlan`), Task 6 (`ProductPlan` / `ProductFillerBuilder`).

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/FashionSeedTaxonomyParityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTaxonomy;
use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTaxonomy;

/**
 * `FashionSeedTaxonomy` exists only because `tests/` is not autoloaded inside a running Shopware
 * installation (HANDOFF.md, Task A) — the console command needs the same taxonomy the fixture uses, so
 * it is duplicated into `src/`. This test is what keeps the duplication honest: if the two classes ever
 * diverge, the seeded shop and the fixture eval stop measuring the same catalogue and nobody would know
 * from a green suite alone.
 */
final class FashionSeedTaxonomyParityTest extends TestCase
{
    public function testConstantsAreIdentical(): void
    {
        $fixture = new \ReflectionClass(FashionTaxonomy::class);
        $seed = new \ReflectionClass(FashionSeedTaxonomy::class);

        self::assertSame($fixture->getConstants(), $seed->getConstants());
    }

    public function testEveryGarmentLeafMatches(): void
    {
        self::assertSame(FashionTaxonomy::leafCount(), FashionSeedTaxonomy::leafCount());

        for ($index = 0; $index < FashionTaxonomy::leafCount(); ++$index) {
            self::assertSame(FashionTaxonomy::garmentLeaf($index), FashionSeedTaxonomy::garmentLeaf($index));
        }
    }

    public function testEverySideLeafMatches(): void
    {
        self::assertSame(FashionTaxonomy::sideLeafCount(), FashionSeedTaxonomy::sideLeafCount());

        for ($index = 0; $index < FashionTaxonomy::sideLeafCount(); ++$index) {
            self::assertSame(FashionTaxonomy::sideLeaf($index), FashionSeedTaxonomy::sideLeaf($index));
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/FashionSeedTaxonomyParityTest.php`
Expected: FAIL with "Class ... FashionSeedTaxonomy not found".

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/FashionSeedTaxonomy.php` — copy `tests/Fixtures/Fashion/FashionTaxonomy.php`
verbatim into the new namespace (constants, `leafCount()`, `sideLeafCount()`, `garmentLeaf()`,
`sideLeaf()`; drop the `FashionTaxonomyTest`-facing commentary, keep the "never contain the word
wedding" warning since it is the actual invariant, not decoration):

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The fashion category tree, duplicated from {@see \Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTaxonomy}.
 *
 * `tests/` is not autoloaded inside a running Shopware installation, so the seed command cannot use the
 * fixture class directly. {@see FashionSeedTaxonomyParityTest} asserts this file matches it exactly —
 * that test is what makes this duplication safe rather than a second, driftable taxonomy.
 *
 * **The word "wedding" must never appear here** — see the fixture class's docblock; the same trap
 * (`fw-occasion-word`) is what this seeded shop exists to let the real assistant fail or succeed at.
 */
final class FashionSeedTaxonomy
{
    public const DEPARTMENTS = ['Women', 'Men', 'Kids'];

    /** @var list<string> */
    public const GARMENT_TYPES = [
        'Dresses', 'Tops', 'Knitwear', 'Trousers', 'Skirts', 'Suits & Tailoring', 'Outerwear', 'Shoes',
        'Bags & Accessories', 'Occasion & Party', 'Swimwear', 'Denim', 'Loungewear', 'Activewear',
    ];

    /** @var list<string> */
    public const GARMENT_SINGULARS = [
        'Dress', 'Top', 'Jumper', 'Trouser', 'Skirt', 'Suit', 'Coat', 'Shoe', 'Bag', 'Gown', 'Swimsuit',
        'Jean', 'Lounge Set', 'Legging',
    ];

    /** @var list<string> */
    public const GARMENT_PLURALS = [
        'Dresses', 'Tops', 'Jumpers', 'Trousers', 'Skirts', 'Suits', 'Coats', 'Shoes', 'Bags', 'Gowns',
        'Swimsuits', 'Jeans', 'Lounge Sets', 'Leggings',
    ];

    public const CUTS = [
        'Maxi', 'Midi', 'Mini', 'Wrap', 'Shirt', 'Slip', 'Bodycon', 'A-Line', 'Shift', 'Sheath', 'Tea',
        'Smock', 'Tiered', 'Cami', 'Halter', 'Pinafore', 'Knitted', 'Cropped', 'Oversized', 'Tailored',
        'Relaxed', 'Slim',
    ];

    public const BRANDS = [
        'Aurelia', 'Bergfeld', 'Calder', 'Dunmore', 'Everlyn', 'Fairholt', 'Garrick', 'Halloway',
        'Ingram', 'Jarrow', 'Kestrel', 'Lorne', 'Mirrenden', 'Norbury', 'Oakfield', 'Pemberton',
        'Quennell', 'Radcliffe', 'Salterton', 'Thackeray', 'Underhill', 'Vansittart', 'Wexford',
        'Yarrow', 'Ashcombe', 'Blackmoor', 'Cranleigh', 'Denholm', 'Elverton', 'Foxholme', 'Grantley',
        'Hensford', 'Ilbury', 'Jessamy', 'Kelmscott', 'Langmere', 'Marchmont', 'Netherby', 'Ostler',
        'Prideaux',
    ];

    public const SEASONS = ['Spring/Summer', 'Autumn/Winter', 'Resort', 'Pre-Fall'];

    public const OCCASIONS = [
        'Party', 'Evening', 'Cocktail', 'Black Tie', 'Garden Party', 'Christening', 'Graduation',
        'Prom', 'Race Day', 'Festival',
    ];

    private function __construct() {}

    public static function leafCount(): int
    {
        return \count(self::DEPARTMENTS) * \count(self::GARMENT_TYPES) * \count(self::CUTS);
    }

    public static function sideLeafCount(): int
    {
        return \count(self::BRANDS) + \count(self::SEASONS) + \count(self::OCCASIONS);
    }

    /**
     * @return array{path: list<string>, name: string}
     */
    public static function garmentLeaf(int $index): array
    {
        $perDepartment = \count(self::GARMENT_TYPES) * \count(self::CUTS);
        $department = self::DEPARTMENTS[intdiv($index, $perDepartment) % \count(self::DEPARTMENTS)] ?? 'Women';

        $withinDepartment = $index % $perDepartment;
        $garment = intdiv($withinDepartment, \count(self::CUTS));
        $type = self::GARMENT_TYPES[$garment] ?? 'Dresses';
        $one = self::GARMENT_SINGULARS[$garment] ?? 'Dress';
        $many = self::GARMENT_PLURALS[$garment] ?? 'Dresses';
        $cut = self::CUTS[$withinDepartment % \count(self::CUTS)] ?? 'Maxi';

        return ['path' => [$department, $type, $cut . ' ' . $many], 'name' => $cut . ' ' . $one];
    }

    /**
     * @return array{path: list<string>, name: string}
     */
    public static function sideLeaf(int $index): array
    {
        $brands = \count(self::BRANDS);
        $seasons = \count(self::SEASONS);

        if ($index < $brands) {
            $brand = self::BRANDS[$index] ?? 'Aurelia';

            return ['path' => ['Brand', $brand], 'name' => $brand . ' Signature Shirt'];
        }

        if ($index < ($brands + $seasons)) {
            $season = self::SEASONS[$index - $brands] ?? 'Resort';

            return ['path' => ['Season', $season], 'name' => $season . ' Edit Blouse'];
        }

        $occasion = self::OCCASIONS[$index - $brands - $seasons] ?? 'Party';

        return ['path' => ['Occasion', $occasion], 'name' => $occasion . ' Edit Jacket'];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/FashionSeedTaxonomyParityTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Command/Seed/FashionSeedTaxonomy.php tests/Command/Seed/FashionSeedTaxonomyParityTest.php
git commit -m "feat(seed): duplicate the fashion taxonomy for the DAL seeder"
```

---

### Task 2: Duplicate the trap product definitions

**Files:**
- Create: `src/Command/Seed/FashionSeedTraps.php`
- Test: `tests/Command/Seed/FashionSeedTrapsParityTest.php`

**Interfaces:**
- Produces: `FashionSeedTraps::OCCASION_DRESS_PREFIX`, `::OCCASION_SUIT_PREFIX`, `::YOGA_PREFIX`,
  `::FALSE_FRIEND_ID` (same string values as the fixture's `FashionTrapProducts`), and
  `FashionSeedTraps::all(): list<array{id: string, name: string, description: string, price: float,
  categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool}>` — one entry per
  trap product (17 total: 6 dresses + 6 suits + 4 yoga + 1 false friend). `sizes: false` marks the false
  friend as variant-free, matching the fixture.
- Consumed by: Task 6 (`ProductPlan`).

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/FashionSeedTrapsParityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTraps;
use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTrapProducts;

/**
 * Same reasoning as {@see FashionSeedTaxonomyParityTest}: the seed command cannot see `tests/`, so the
 * four named traps (`fw-gender-split`'s two halves, `fw-false-friend`, `fw-undivided`) are duplicated
 * into `src/`. Without this test a stray edit to either copy silently stops the seeded shop and the
 * fixture eval from being the same measurement.
 */
final class FashionSeedTrapsParityTest extends TestCase
{
    public function testPrefixesAndIdsMatch(): void
    {
        self::assertSame(FashionTrapProducts::OCCASION_DRESS_PREFIX, FashionSeedTraps::OCCASION_DRESS_PREFIX);
        self::assertSame(FashionTrapProducts::OCCASION_SUIT_PREFIX, FashionSeedTraps::OCCASION_SUIT_PREFIX);
        self::assertSame(FashionTrapProducts::YOGA_PREFIX, FashionSeedTraps::YOGA_PREFIX);
        self::assertSame(FashionTrapProducts::FALSE_FRIEND_ID, FashionSeedTraps::FALSE_FRIEND_ID);
    }

    public function testEveryTrapNameAndCategoryPathMatchesTheFixture(): void
    {
        $fixture = FashionTrapProducts::all();
        $seed = FashionSeedTraps::all();

        self::assertCount(\count($fixture), $seed);

        foreach ($fixture as $index => $fixtureProduct) {
            self::assertSame($fixtureProduct['id'], $seed[$index]['id']);
            self::assertSame($fixtureProduct['name'], $seed[$index]['name']);
            self::assertSame($fixtureProduct['categoryPath'], $seed[$index]['categoryPath']);
            self::assertSame($fixtureProduct['properties'], $seed[$index]['properties']);
        }
    }

    /**
     * The premise the whole trap set rests on (`fw-occasion-word`): "wedding" appears in exactly the
     * false friend and nowhere else. Asserted over the encoded output, mirroring
     * `FashionTrapPresenceTest` on the fixture side rather than trusting a comment.
     */
    public function testWeddingAppearsOnlyInTheFalseFriend(): void
    {
        foreach (FashionSeedTraps::all() as $product) {
            $haystack = strtolower($product['name'] . ' ' . $product['description']);
            $mentionsWedding = str_contains($haystack, 'wedding');

            self::assertSame($product['id'] === FashionSeedTraps::FALSE_FRIEND_ID, $mentionsWedding, $product['id']);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/FashionSeedTrapsParityTest.php`
Expected: FAIL with "Class ... FashionSeedTraps not found".

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/FashionSeedTraps.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The four named traps of the occasion-query spec, duplicated from
 * {@see \Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionTrapProducts} for the same reason
 * {@see FashionSeedTaxonomy} duplicates the category shape — `tests/` is not autoloaded inside a
 * running Shopware installation. {@see FashionSeedTrapsParityTest} keeps the two in sync.
 *
 * Unlike the fixture class this returns plain description arrays rather than a full product shape —
 * {@see ProductPlan} owns turning these into DAL write payloads (ids, prices, variants), the same
 * division {@see FashionTaxonomy} and {@see \Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionCatalogGenerator}
 * already use between "what the shop sells" and "how much of it there is".
 */
final class FashionSeedTraps
{
    public const OCCASION_DRESS_PREFIX = 'fw-occ-dress-';
    public const OCCASION_SUIT_PREFIX = 'fw-occ-suit-';
    public const FALSE_FRIEND_ID = 'fw-false-friend';
    public const YOGA_PREFIX = 'fw-yoga-';

    /** @var list<array{cut: string, colour: string, material: string}> */
    private const DRESSES = [
        ['cut' => 'Silk Slip Occasion Dress', 'colour' => 'Ivory', 'material' => 'Silk'],
        ['cut' => 'Pleated Midi Occasion Dress', 'colour' => 'Sage', 'material' => 'Viscose'],
        ['cut' => 'Draped Satin Occasion Gown', 'colour' => 'Navy', 'material' => 'Satin'],
        ['cut' => 'Embroidered Tulle Occasion Dress', 'colour' => 'Blush', 'material' => 'Cotton'],
        ['cut' => 'Cape-Back Occasion Dress', 'colour' => 'Emerald', 'material' => 'Silk'],
        ['cut' => 'Tiered Chiffon Occasion Dress', 'colour' => 'Lilac', 'material' => 'Viscose'],
    ];

    /** @var list<array{cut: string, colour: string, material: string}> */
    private const SUITS = [
        ['cut' => 'Three-Piece Occasion Suit', 'colour' => 'Navy', 'material' => 'Wool'],
        ['cut' => 'Linen Occasion Suit', 'colour' => 'Stone', 'material' => 'Linen'],
        ['cut' => 'Double-Breasted Occasion Suit', 'colour' => 'Charcoal', 'material' => 'Wool'],
        ['cut' => 'Slim Morning Suit', 'colour' => 'Slate', 'material' => 'Wool'],
        ['cut' => 'Velvet Dinner Jacket Suit', 'colour' => 'Burgundy', 'material' => 'Velvet'],
        ['cut' => 'Herringbone Occasion Suit', 'colour' => 'Camel', 'material' => 'Wool'],
    ];

    /** @var list<array{cut: string, colour: string, material: string}> */
    private const YOGA = [
        ['cut' => 'High-Waist Yoga Legging', 'colour' => 'Black', 'material' => 'Jersey'],
        ['cut' => 'Seamless Yoga Bra Top', 'colour' => 'Sage', 'material' => 'Jersey'],
        ['cut' => 'Wide-Leg Yoga Trouser', 'colour' => 'Slate', 'material' => 'Tencel'],
        ['cut' => 'Wrap Yoga Cardigan', 'colour' => 'Cream', 'material' => 'Cotton'],
    ];

    private function __construct() {}

    /**
     * @return list<array{
     *     id: string, name: string, description: string, price: float,
     *     categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool,
     * }>
     */
    public static function all(): array
    {
        return [
            ...self::family(self::DRESSES, self::OCCASION_DRESS_PREFIX, ['Women', 'Occasion & Party', 'Occasion Dresses'], 189.0),
            ...self::family(self::SUITS, self::OCCASION_SUIT_PREFIX, ['Men', 'Suits & Tailoring', 'Occasion Suits'], 349.0),
            ...self::family(self::YOGA, self::YOGA_PREFIX, ['Women', 'Activewear', 'Yoga'], 59.0),
            self::falseFriend(),
        ];
    }

    /**
     * @param list<array{cut: string, colour: string, material: string}> $items
     * @param list<string>                                              $path
     *
     * @return list<array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool}>
     */
    private static function family(array $items, string $prefix, array $path, float $basePrice): array
    {
        $products = [];

        foreach ($items as $offset => $item) {
            $products[] = [
                'id' => $prefix . ($offset + 1),
                'name' => $item['cut'],
                'description' => \sprintf('%s in %s %s.', $item['cut'], strtolower($item['colour']), strtolower($item['material'])),
                'price' => round($basePrice + ($offset * 20.0), 2),
                'categoryPath' => $path,
                'properties' => ['Colour' => [$item['colour']], 'Material' => [$item['material']]],
                'sizes' => true,
            ];
        }

        return $products;
    }

    /**
     * @return array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool}
     */
    private static function falseFriend(): array
    {
        return [
            'id' => self::FALSE_FRIEND_ID,
            'name' => 'Wedding Cake Topper Charm',
            'description' => 'Small enamel keepsake charm, boxed.',
            'price' => 12.0,
            'categoryPath' => ['Gifts & Novelty', 'Keepsakes'],
            'properties' => ['Material' => ['Silver']],
            'sizes' => false,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/FashionSeedTrapsParityTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Command/Seed/FashionSeedTraps.php tests/Command/Seed/FashionSeedTrapsParityTest.php
git commit -m "feat(seed): duplicate the fashion trap products for the DAL seeder"
```

---

### Task 3: Deterministic ids

**Files:**
- Create: `src/Command/Seed/SeedId.php`
- Test: `tests/Command/Seed/SeedIdTest.php`

**Interfaces:**
- Produces: `SeedId::forPath(string $namespace, string $path): string` — a 32-lowercase-hex-char id
  (matches Shopware's `Uuid::VALID_PATTERN`), stable for a given `(namespace, path)` pair.
- Consumed by: Tasks 4, 5, 6 (category, property/option, product/variant ids), Task 7 (the marker id).

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/SeedIdTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\SeedId;

/**
 * Every id this feature writes comes from here rather than `Uuid::randomHex()` — Global Constraints:
 * a re-run that slips past {@see \Swag\AssistantStarterKit\Command\Seed\SeedGuard} attempts to recreate
 * the same rows rather than silently doubling the catalogue, and a diff between two runs of the plan
 * classes is meaningful instead of noise.
 */
final class SeedIdTest extends TestCase
{
    public function testIdIsThirtyTwoLowercaseHexChars(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SeedId::forPath('category', 'Women/Dresses'));
    }

    public function testSamePathIsStable(): void
    {
        self::assertSame(
            SeedId::forPath('category', 'Women/Dresses'),
            SeedId::forPath('category', 'Women/Dresses'),
        );
    }

    public function testDifferentPathsDiffer(): void
    {
        self::assertNotSame(
            SeedId::forPath('category', 'Women/Dresses'),
            SeedId::forPath('category', 'Women/Tops'),
        );
    }

    /** Namespaces exist so a category path and a product id can never collide even if the raw strings coincide. */
    public function testDifferentNamespacesDifferForTheSamePath(): void
    {
        self::assertNotSame(
            SeedId::forPath('category', 'fw-false-friend'),
            SeedId::forPath('product', 'fw-false-friend'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/SeedIdTest.php`
Expected: FAIL with "Class ... SeedId not found".

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/SeedId.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * Turns a stable string into a Shopware-valid 32-hex-char id.
 *
 * Deterministic rather than `Uuid::randomHex()` — see Global Constraints in the seeder plan. A sha256
 * hash truncated to 32 hex chars keeps the collision risk at the same order as a real UUID4 while
 * costing nothing to reproduce from a path string alone, which is what lets {@see CategoryTreePlan}
 * build parent/child links before anything is written.
 */
final class SeedId
{
    private function __construct() {}

    public static function forPath(string $namespace, string $path): string
    {
        return substr(hash('sha256', $namespace . '::' . $path), 0, 32);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/SeedIdTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Command/Seed/SeedId.php tests/Command/Seed/SeedIdTest.php
git commit -m "feat(seed): deterministic id derivation for the DAL seeder"
```

---

### Task 4: The category tree plan

**Files:**
- Create: `src/Command/Seed/CategoryTreePlan.php`
- Test: `tests/Command/Seed/CategoryTreePlanTest.php`

**Interfaces:**
- Consumes: `FashionSeedTaxonomy` (Task 1), `SeedId::forPath()` (Task 3).
- Produces: `CategoryTreePlan::build(string $navigationRootId): array{tree: list<array<string, mixed>>,
  idsByPath: array<string, string>}`. `tree` is a list of nested category payloads — each entry shaped
  like `['id' => ..., 'parentId' => $navigationRootId, 'name' => ..., 'active' => true, 'children' =>
  [...]]`, ready for one `EntityRepository::create()` call (Task 8/`SeedWriter`). `idsByPath` maps a
  `/`-joined path (e.g. `"Women/Dresses/Maxi Dresses"`, `"Brand/Aurelia"`,
  `"Gifts & Novelty/Keepsakes"`) to that leaf's id, for `ProductPlan` (Task 6) to assign products to
  categories.
- Consumed by: Task 6 (`ProductPlan`), Task 8 (`SeedWriter`).

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/CategoryTreePlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\CategoryTreePlan;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTaxonomy;

/**
 * The node-count arithmetic is measured in the seeder plan's Context section (969 + 57 + 5 = 1,031,
 * excluding the 12 nodes the fixture's twelve real products contribute — this seeder never touches
 * those). This test is what makes "1,031" a fact about the code rather than a claim in a markdown file.
 */
final class CategoryTreePlanTest extends TestCase
{
    private const ROOT = 'root0000000000000000000000000000';

    public function testTotalNodeCountIsOneThousandThirtyOne(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        self::assertCount(1_031, $result['idsByPath']);
    }

    public function testEveryGarmentLeafPathIsPresent(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        for ($index = 0; $index < FashionSeedTaxonomy::leafCount(); ++$index) {
            $leaf = FashionSeedTaxonomy::garmentLeaf($index);
            self::assertArrayHasKey(implode('/', $leaf['path']), $result['idsByPath']);
        }
    }

    public function testEverySideLeafPathIsPresent(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        for ($index = 0; $index < FashionSeedTaxonomy::sideLeafCount(); ++$index) {
            $leaf = FashionSeedTaxonomy::sideLeaf($index);
            self::assertArrayHasKey(implode('/', $leaf['path']), $result['idsByPath']);
        }
    }

    public function testTrapLeavesArePresentUnderTheirExistingTypeNodes(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        self::assertArrayHasKey('Women/Occasion & Party/Occasion Dresses', $result['idsByPath']);
        self::assertArrayHasKey('Men/Suits & Tailoring/Occasion Suits', $result['idsByPath']);
        self::assertArrayHasKey('Women/Activewear/Yoga', $result['idsByPath']);
        self::assertArrayHasKey('Gifts & Novelty/Keepsakes', $result['idsByPath']);
    }

    public function testTreeHasSevenTopLevelBranches(): void
    {
        $result = CategoryTreePlan::build(self::ROOT);

        // Women, Men, Kids, Brand, Season, Occasion, Gifts & Novelty.
        self::assertCount(7, $result['tree']);

        foreach ($result['tree'] as $node) {
            self::assertSame(self::ROOT, $node['parentId']);
        }
    }

    public function testIdsAreStableAcrossTwoBuilds(): void
    {
        self::assertSame(
            CategoryTreePlan::build(self::ROOT)['idsByPath'],
            CategoryTreePlan::build(self::ROOT)['idsByPath'],
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/CategoryTreePlanTest.php`
Expected: FAIL with "Class ... CategoryTreePlan not found".

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/CategoryTreePlan.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * Builds the fashion category tree as nested payload arrays plus a path-to-id lookup, entirely in
 * memory — no Shopware dependency, so this is unit tested directly rather than against a container.
 *
 * Nested rather than flat: Shopware's `EntityRepository::create()` accepts a self-referencing
 * `children` key (the same shape
 * `vendor/shopware/core/Framework/Demodata/Generator/CategoryGenerator.php` writes), so the whole
 * 1,031-node tree is one write in {@see SeedWriter}, not a batched, parent-before-child sequence.
 *
 * Trap leaves (`Occasion Dresses`, `Occasion Suits`, `Yoga`) are appended onto garment-type nodes this
 * class already builds — `FashionSeedTraps` supplies the category **path**, this class is the only
 * place that turns any category path into an id, which is what keeps `idsByPath` the single source of
 * truth `ProductPlan` reads from.
 */
final class CategoryTreePlan
{
    private const NAMESPACE = 'category';

    private function __construct() {}

    /**
     * @return array{tree: list<array<string, mixed>>, idsByPath: array<string, string>}
     */
    public static function build(string $navigationRootId): array
    {
        $idsByPath = [];
        $tree = [
            ...self::departments($navigationRootId, $idsByPath),
            self::sideBranch($navigationRootId, 'Brand', FashionSeedTaxonomy::BRANDS, $idsByPath),
            self::sideBranch($navigationRootId, 'Season', FashionSeedTaxonomy::SEASONS, $idsByPath),
            self::sideBranch($navigationRootId, 'Occasion', FashionSeedTaxonomy::OCCASIONS, $idsByPath),
            self::giftsAndNovelty($navigationRootId, $idsByPath),
        ];

        return ['tree' => $tree, 'idsByPath' => $idsByPath];
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return list<array<string, mixed>>
     */
    private static function departments(string $rootId, array &$idsByPath): array
    {
        $departments = [];

        foreach (FashionSeedTaxonomy::DEPARTMENTS as $department) {
            $departmentId = self::register($idsByPath, $department, $department);
            $types = [];

            foreach (FashionSeedTaxonomy::GARMENT_TYPES as $typeIndex => $type) {
                $typePath = $department . '/' . $type;
                $typeId = self::register($idsByPath, $typePath, $typePath);
                $types[] = [
                    'id' => $typeId,
                    'parentId' => $departmentId,
                    'name' => $type,
                    'active' => true,
                    'children' => [
                        ...self::cutLeaves($department, $typeIndex, $typeId, $idsByPath),
                        ...self::trapLeaves($department, $type, $typeId, $idsByPath),
                    ],
                ];
            }

            $departments[] = [
                'id' => $departmentId, 'parentId' => $rootId, 'name' => $department, 'active' => true,
                'children' => $types,
            ];
        }

        return $departments;
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return list<array<string, mixed>>
     */
    private static function cutLeaves(string $department, int $typeIndex, string $typeId, array &$idsByPath): array
    {
        $leaves = [];
        $perDepartment = \count(FashionSeedTaxonomy::GARMENT_TYPES) * \count(FashionSeedTaxonomy::CUTS);
        $departmentIndex = array_search($department, FashionSeedTaxonomy::DEPARTMENTS, true);
        \assert(\is_int($departmentIndex));
        $base = ($departmentIndex * $perDepartment) + ($typeIndex * \count(FashionSeedTaxonomy::CUTS));

        for ($cutOffset = 0; $cutOffset < \count(FashionSeedTaxonomy::CUTS); ++$cutOffset) {
            $leaf = FashionSeedTaxonomy::garmentLeaf($base + $cutOffset);
            $path = implode('/', $leaf['path']);
            $leaves[] = [
                'id' => self::register($idsByPath, $path, $path),
                'parentId' => $typeId,
                'name' => end($leaf['path']),
                'active' => true,
            ];
        }

        return $leaves;
    }

    /**
     * The three trap-only leaves: correct only when `$department`/`$type` is the branch each trap
     * lives under. `FashionSeedTraps::all()` is the single source of the category paths, so a future
     * trap needs no change here beyond adding its path to that match.
     *
     * @param array<string, string> $idsByPath
     *
     * @return list<array<string, mixed>>
     */
    private static function trapLeaves(string $department, string $type, string $typeId, array &$idsByPath): array
    {
        $leafNames = [];

        if ($department === 'Women' && $type === 'Occasion & Party') {
            $leafNames[] = 'Occasion Dresses';
        }
        if ($department === 'Men' && $type === 'Suits & Tailoring') {
            $leafNames[] = 'Occasion Suits';
        }
        if ($department === 'Women' && $type === 'Activewear') {
            $leafNames[] = 'Yoga';
        }

        $leaves = [];
        foreach ($leafNames as $name) {
            $path = $department . '/' . $type . '/' . $name;
            $leaves[] = [
                'id' => self::register($idsByPath, $path, $name),
                'parentId' => $typeId,
                'name' => $name,
                'active' => true,
            ];
        }

        return $leaves;
    }

    /**
     * @param list<string>           $values
     * @param array<string, string>  $idsByPath
     *
     * @return array<string, mixed>
     */
    private static function sideBranch(string $rootId, string $branch, array $values, array &$idsByPath): array
    {
        $branchId = self::register($idsByPath, $branch, $branch);
        $children = [];

        foreach ($values as $value) {
            $path = $branch . '/' . $value;
            $children[] = [
                'id' => self::register($idsByPath, $path, $value),
                'parentId' => $branchId,
                'name' => $value,
                'active' => true,
            ];
        }

        return ['id' => $branchId, 'parentId' => $rootId, 'name' => $branch, 'active' => true, 'children' => $children];
    }

    /**
     * @param array<string, string> $idsByPath
     *
     * @return array<string, mixed>
     */
    private static function giftsAndNovelty(string $rootId, array &$idsByPath): array
    {
        $branchId = self::register($idsByPath, 'Gifts & Novelty', 'Gifts & Novelty');
        $leafPath = 'Gifts & Novelty/Keepsakes';

        return [
            'id' => $branchId, 'parentId' => $rootId, 'name' => 'Gifts & Novelty', 'active' => true,
            'children' => [[
                'id' => self::register($idsByPath, $leafPath, 'Keepsakes'),
                'parentId' => $branchId,
                'name' => 'Keepsakes',
                'active' => true,
            ]],
        ];
    }

    /**
     * @param array<string, string> $idsByPath
     */
    private static function register(array &$idsByPath, string $path, string $namePart): string
    {
        $id = SeedId::forPath(self::NAMESPACE, $path);
        $idsByPath[$path] = $id;

        return $id;
    }
}
```

Note: `$namePart` is accepted but unused in `register()` in the version above — drop the parameter and
call sites that pass it (`self::register($idsByPath, $path, $department)` etc. only ever need `$path`).
Fix before running: change `register()`'s signature to `(array &$idsByPath, string $path): string` and
update every call site to drop the third argument.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/CategoryTreePlanTest.php`
Expected: PASS, 5 tests. If the count assertion fails, print `array_keys($result['idsByPath'])` sorted
and diff by eye against the Context table above before changing the assertion — the arithmetic is
measured, not guessed, so a mismatch is almost always the tree-building code, not the expected number.

- [ ] **Step 5: Commit**

```bash
git add src/Command/Seed/CategoryTreePlan.php tests/Command/Seed/CategoryTreePlanTest.php
git commit -m "feat(seed): build the fashion category tree as DAL-ready payloads"
```

---

### Task 5: The property group plan

**Files:**
- Create: `src/Command/Seed/PropertyGroupPlan.php`
- Test: `tests/Command/Seed/PropertyGroupPlanTest.php`

**Interfaces:**
- Consumes: `SeedId::forPath()` (Task 3).
- Produces: `PropertyGroupPlan::build(): array{groups: list<array<string, mixed>>, optionIds:
  array<string, array<string, string>>, sizeOptionIds: array<string, string>}`. `groups` is the full
  write payload for `property_group.repository->create()` — three non-variant groups (`Colour`,
  `Material`, `Pattern`) plus one variant-defining group (`Size`). `optionIds['Colour']['Ivory']` etc.
  resolves a non-variant value to its option id; `sizeOptionIds['M']` etc. resolves a size directly,
  since every product that has variants uses the same five sizes.
- Consumed by: Task 6 (`ProductPlan`), Task 8 (`SeedWriter`).

This plan deliberately does **not** duplicate `FashionCatalogGenerator`'s private `COLOURS` /
`MATERIALS` / `PATTERNS` lists — those are the fixture's own filler vocabulary, not part of the taxonomy
`HANDOFF.md` asks this seeder to match, and they are `private const`, not part of any contract another
class relies on. This plan's own, smaller vocabulary is enough to give the seeded shop non-trivial
`properties` facets; the trap products' `Colour`/`Material` values (Task 2) must still resolve against
whatever this class defines, which the test below checks directly.

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/PropertyGroupPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTraps;
use Swag\AssistantStarterKit\Command\Seed\PropertyGroupPlan;

final class PropertyGroupPlanTest extends TestCase
{
    public function testBuildsFourGroups(): void
    {
        $result = PropertyGroupPlan::build();

        $names = array_map(static fn(array $group): mixed => $group['name'], $result['groups']);
        self::assertSame(['Colour', 'Material', 'Pattern', 'Size'], $names);
    }

    public function testSizeOptionsCoverTheFiveSizesEverySeededVariantUses(): void
    {
        $result = PropertyGroupPlan::build();

        self::assertSame(['XS', 'S', 'M', 'L', 'XL'], array_keys($result['sizeOptionIds']));

        foreach ($result['sizeOptionIds'] as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
    }

    /**
     * Every colour/material value a trap product names (Task 2) must resolve here, or {@see ProductPlan}
     * would have no option id to assign it — this is the seam between the two duplicated word lists.
     */
    public function testEveryTrapColourAndMaterialResolves(): void
    {
        $result = PropertyGroupPlan::build();

        foreach (FashionSeedTraps::all() as $product) {
            foreach ($product['properties'] as $group => $values) {
                foreach ($values as $value) {
                    self::assertArrayHasKey($value, $result['optionIds'][$group], "$group:$value");
                }
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/PropertyGroupPlanTest.php`
Expected: FAIL with "Class ... PropertyGroupPlan not found".

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/PropertyGroupPlan.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The property groups this catalogue's products carry: three non-variant groups that populate
 * `DalCommerceGateway::facets()`'s `properties` aggregation (Colour, Material, Pattern), and one
 * variant-defining `Size` group that populates its `options` aggregation and drives
 * {@see ProductPlan}'s size families.
 *
 * `FashionSeedTrapsParityTest`'s colours and materials must all resolve here —
 * {@see PropertyGroupPlanTest::testEveryTrapColourAndMaterialResolves} is the seam test; a colour named
 * in a trap but missing here is a `ProductPlan` write with no option id to assign.
 */
final class PropertyGroupPlan
{
    private const NAMESPACE = 'property';

    /** Covers every colour {@see FashionSeedTraps} names plus enough spread for filler products. */
    private const COLOURS = [
        'Black', 'Ivory', 'Navy', 'Camel', 'Sage', 'Rust', 'Blush', 'Slate', 'Olive', 'Burgundy',
        'Cobalt', 'Emerald', 'Lilac', 'Charcoal', 'Stone',
    ];

    private const MATERIALS = ['Cotton', 'Linen', 'Silk', 'Wool', 'Viscose', 'Satin', 'Velvet', 'Jersey', 'Tencel'];

    private const PATTERNS = ['Plain', 'Striped', 'Floral', 'Checked', 'Polka Dot'];

    private const SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    private function __construct() {}

    /**
     * @return array{groups: list<array<string, mixed>>, optionIds: array<string, array<string, string>>, sizeOptionIds: array<string, string>}
     */
    public static function build(): array
    {
        $optionIds = [];
        $groups = [
            self::group('Colour', self::COLOURS, $optionIds),
            self::group('Material', self::MATERIALS, $optionIds),
            self::group('Pattern', self::PATTERNS, $optionIds),
        ];

        $sizeOptionIds = [];
        $groups[] = self::group('Size', self::SIZES, $sizeOptionIdsAsGroupShape = []);
        foreach (self::SIZES as $size) {
            $sizeOptionIds[$size] = SeedId::forPath(self::NAMESPACE, 'Size/' . $size);
        }

        return ['groups' => $groups, 'optionIds' => $optionIds, 'sizeOptionIds' => $sizeOptionIds];
    }

    /**
     * @param list<string>                       $values
     * @param array<string, array<string, string>> $optionIds
     *
     * @return array<string, mixed>
     */
    private static function group(string $name, array $values, array &$optionIds): array
    {
        $options = [];
        foreach ($values as $value) {
            $id = SeedId::forPath(self::NAMESPACE, $name . '/' . $value);
            $optionIds[$name][$value] = $id;
            $options[] = ['id' => $id, 'name' => $value];
        }

        return ['id' => SeedId::forPath(self::NAMESPACE, $name), 'name' => $name, 'options' => $options];
    }
}
```

The `$sizeOptionIdsAsGroupShape` local above is a placeholder for reusing `group()` — simplify during
implementation: call `self::group('Size', self::SIZES, $ignored)` with a throwaway `$ignored = []`
by-ref array (its per-group `optionIds['Size'][...]` entries are simply not read), then build
`$sizeOptionIds` from `self::SIZES` with `SeedId::forPath()` directly as shown. Confirm the group's `id`
and its options' `id`s match what `$sizeOptionIds` computes (same namespace, same path scheme) — the
test only checks `sizeOptionIds`, but a mismatch between the write payload's option ids and the ids
`ProductPlan` assigns to variants would silently produce variants with no configurator option, so keep
both derivations calling `SeedId::forPath('property', 'Size/' . $size)` for the identical `$size`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/PropertyGroupPlanTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Command/Seed/PropertyGroupPlan.php tests/Command/Seed/PropertyGroupPlanTest.php
git commit -m "feat(seed): build the fashion property groups as DAL-ready payloads"
```

---

### Task 6: The product plan

**Files:**
- Create: `src/Command/Seed/SizeFamily.php`
- Create: `src/Command/Seed/ProductFillerBuilder.php`
- Create: `src/Command/Seed/ProductPlan.php`
- Test: `tests/Command/Seed/ProductPlanTest.php`

**Interfaces:**
- Consumes: `FashionSeedTaxonomy`, `FashionSeedTraps`, `SeedId::forPath()`,
  `CategoryTreePlan::build()['idsByPath']`, `PropertyGroupPlan::build()['optionIds' | 'sizeOptionIds']`.
- Produces: `ProductPlan::build(array $categoryIdsByPath, array $optionIds, array $sizeOptionIds, string
  $taxId): list<array<string, mixed>>` — one DAL write payload per top-level product (traps + filler),
  each shaped like `ProductGenerator::createSimpleProduct()`'s payload plus, where `sizes: true`,
  `children` and `configuratorSettings` shaped like its `buildVariants()`. `SizeFamily::build(string
  $parentId, string $parentNumber, float $price, string $taxId, array $sizeOptionIds, int $seed):
  array{children: list<array<string,mixed>>, configuratorSettings: list<array<string,mixed>>}` is the
  shared variant builder both `ProductPlan` (traps) and `ProductFillerBuilder` (filler) call, so the
  five-size shape is written once.
- Produces (constants): `ProductPlan::PRODUCT_COUNT === 3_617`, `ProductPlan::SELLABLE_UNITS === 15_201`
  — asserted, not computed twice.
- Consumed by: Task 8 (`SeedWriter`/`SeedRunner`).

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/ProductPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\CategoryTreePlan;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTraps;
use Swag\AssistantStarterKit\Command\Seed\ProductPlan;
use Swag\AssistantStarterKit\Command\Seed\PropertyGroupPlan;

/**
 * The counts here are the seeder plan's Context table made executable: 3,617 top-level products,
 * 15,201 sellable units (720 variant-free filler + 2,880×5 sized filler + (6+6+4)×5 sized traps + 1
 * variant-free false friend). A change to either number must come with a change to that table.
 */
final class ProductPlanTest extends TestCase
{
    private const TAX_ID = 'tax00000000000000000000000000000';

    private function plan(): array
    {
        $categories = CategoryTreePlan::build('root0000000000000000000000000000')['idsByPath'];
        $properties = PropertyGroupPlan::build();

        return ProductPlan::build($categories, $properties['optionIds'], $properties['sizeOptionIds'], self::TAX_ID);
    }

    public function testProductCountMatchesTheMeasuredConstant(): void
    {
        self::assertSame(ProductPlan::PRODUCT_COUNT, \count($this->plan()));
        self::assertSame(3_617, ProductPlan::PRODUCT_COUNT);
    }

    public function testSellableUnitCountMatchesTheMeasuredConstant(): void
    {
        $units = 0;
        foreach ($this->plan() as $product) {
            $children = $product['children'] ?? [];
            $units += $children !== [] ? \count($children) : 1;
        }

        self::assertSame(ProductPlan::SELLABLE_UNITS, $units);
        self::assertSame(15_201, ProductPlan::SELLABLE_UNITS);
    }

    public function testEveryTrapProductIsPresentWithItsFixtureId(): void
    {
        $ids = array_column($this->plan(), 'id');

        foreach (FashionSeedTraps::all() as $trap) {
            self::assertContains($trap['id'], $ids, $trap['id']);
        }
    }

    public function testEveryProductIsAssignedToAResolvedCategory(): void
    {
        foreach ($this->plan() as $product) {
            self::assertNotEmpty($product['categories']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $product['categories'][0]['id']);
        }
    }

    public function testTheFalseFriendCarriesNoSizeVariants(): void
    {
        $products = $this->plan();
        $falseFriend = array_values(array_filter(
            $products,
            static fn(array $p): bool => $p['id'] === FashionSeedTraps::FALSE_FRIEND_ID,
        ))[0];

        self::assertArrayNotHasKey('children', $falseFriend);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/ProductPlanTest.php`
Expected: FAIL with "Class ... ProductPlan not found".

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/SizeFamily.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The five-size variant family every sized product in this catalogue carries — shared by
 * {@see ProductPlan} (traps) and {@see ProductFillerBuilder} (filler), the same way the fixture's
 * `FashionCatalogGenerator::sizeFamily()` and `FashionTrapProducts::sizes()` both build five variants
 * and would otherwise duplicate the shape twice here too.
 */
final class SizeFamily
{
    private function __construct() {}

    /**
     * @param array<string, string> $sizeOptionIds `PropertyGroupPlan::build()['sizeOptionIds']`.
     *
     * @return array{children: list<array<string, mixed>>, configuratorSettings: list<array<string, mixed>>}
     */
    public static function build(string $parentId, string $parentNumber, float $price, string $taxId, array $sizeOptionIds, int $stockSeed): array
    {
        $children = [];
        $configuratorSettings = [];
        $offset = 0;

        foreach ($sizeOptionIds as $size => $optionId) {
            $childId = SeedId::forPath('product-variant', $parentId . '/' . $size);
            $children[] = [
                'id' => $childId,
                'productNumber' => $parentNumber . '-' . strtolower($size),
                'price' => [['currencyId' => \Shopware\Core\Defaults::CURRENCY, 'gross' => $price, 'net' => $price, 'linked' => true]],
                'stock' => ($stockSeed + $offset) % 9,
                'options' => [['id' => $optionId]],
            ];
            $configuratorSettings[] = ['optionId' => $optionId];
            ++$offset;
        }

        return ['children' => $children, 'configuratorSettings' => $configuratorSettings];
    }
}
```

`Shopware\Core\Defaults::CURRENCY` is the one Shopware-core reference in the whole Task 1–6 plan chain —
it is a class constant (`'b7d2554b0ce847cd82f3ac9bd1c0dfca'`), not a service, so it needs no container
and this file's tests still run under this repo's plain `vendor/bin/phpunit`. Confirm the constant's
value inside the container before relying on it (`docker compose exec -T web sh -lc "php -r \"echo
\\Shopware\\Core\\Defaults::CURRENCY;\""`) — do not hardcode the literal separately from the constant
reference.

Create `src/Command/Seed/ProductFillerBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The 3,600 generated filler products — the volume the traps (Task 2) sit inside. Mirrors
 * `FashionCatalogGenerator::filler()`'s index arithmetic (the first `sideLeafCount()` indices fill
 * Brand/Season/Occasion one apiece, the rest cycle the garment leaves) so the seeded shop's product
 * distribution across categories matches the fixture's, not a differently-shaped approximation of it.
 *
 * A glibc-constants LCG, not `random_int()` — same reasoning `FashionCatalogGenerator` gives: a
 * deterministic sequence keeps two runs of this plan (and its test) identical.
 */
final class ProductFillerBuilder
{
    public const COUNT = 3_600;

    private const SEED = 20_260_827;

    private function __construct() {}

    /**
     * @param array<string, string>                $categoryIdsByPath
     * @param array<string, array<string, string>>  $optionIds
     * @param array<string, string>                 $sizeOptionIds
     *
     * @return list<array<string, mixed>>
     */
    public static function build(array $categoryIdsByPath, array $optionIds, array $sizeOptionIds, string $taxId): array
    {
        $state = self::SEED;
        $next = static function () use (&$state): int {
            $state = (($state * 1_103_515_245) + 12_345) & 0x7FFF_FFFF;

            return $state;
        };

        $colours = array_keys($optionIds['Colour']);
        $materials = array_keys($optionIds['Material']);
        $sideLeaves = FashionSeedTaxonomy::sideLeafCount();

        $products = [];
        for ($index = 0; $index < self::COUNT; ++$index) {
            $leaf = $index < $sideLeaves
                ? FashionSeedTaxonomy::sideLeaf($index)
                : FashionSeedTaxonomy::garmentLeaf($index - $sideLeaves);

            $path = implode('/', $leaf['path']);
            $categoryId = $categoryIdsByPath[$path] ?? null;
            \assert($categoryId !== null, $path);

            $id = SeedId::forPath('product', 'filler/' . $index);
            $price = round(19.0 + ((float) ($next() % 28_000) / 100.0), 2);
            $colour = $colours[$next() % \count($colours)];
            $material = $materials[$next() % \count($materials)];

            $product = [
                'id' => $id,
                'productNumber' => 'FW-' . strtoupper(substr($id, 0, 12)),
                'name' => \sprintf('%s %04d', $leaf['name'], $index),
                'description' => \sprintf('%s in a considered cut.', $leaf['name']),
                'price' => [['currencyId' => \Shopware\Core\Defaults::CURRENCY, 'gross' => $price, 'net' => $price, 'linked' => true]],
                'taxId' => $taxId,
                'active' => true,
                'stock' => $next() % 12,
                'categories' => [['id' => $categoryId]],
                'properties' => [['id' => $optionIds['Colour'][$colour]], ['id' => $optionIds['Material'][$material]]],
            ];

            if (($index % 5) !== 0) {
                $family = SizeFamily::build($id, $product['productNumber'], $price, $taxId, $sizeOptionIds, $next());
                $product['children'] = $family['children'];
                $product['configuratorSettings'] = $family['configuratorSettings'];
            }

            $products[] = $product;
        }

        return $products;
    }
}
```

Create `src/Command/Seed/ProductPlan.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The full seeded product list: the 17 named traps (Task 2) plus 3,600 generated filler products
 * ({@see ProductFillerBuilder}). Counts are measured (see the seeder plan's Context table) and
 * asserted as constants below, not computed inline and trusted.
 */
final class ProductPlan
{
    public const PRODUCT_COUNT = 3_617;

    public const SELLABLE_UNITS = 15_201;

    private function __construct() {}

    /**
     * @param array<string, string>                $categoryIdsByPath
     * @param array<string, array<string, string>>  $optionIds
     * @param array<string, string>                 $sizeOptionIds
     *
     * @return list<array<string, mixed>>
     */
    public static function build(array $categoryIdsByPath, array $optionIds, array $sizeOptionIds, string $taxId): array
    {
        $traps = array_map(
            fn(array $trap): array => self::trapToPayload($trap, $categoryIdsByPath, $optionIds, $sizeOptionIds, $taxId),
            FashionSeedTraps::all(),
        );

        return [...$traps, ...ProductFillerBuilder::build($categoryIdsByPath, $optionIds, $sizeOptionIds, $taxId)];
    }

    /**
     * @param array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool} $trap
     * @param array<string, string>                                                                                                                                 $categoryIdsByPath
     * @param array<string, array<string, string>>                                                                                                                 $optionIds
     * @param array<string, string>                                                                                                                                $sizeOptionIds
     *
     * @return array<string, mixed>
     */
    private static function trapToPayload(array $trap, array $categoryIdsByPath, array $optionIds, array $sizeOptionIds, string $taxId): array
    {
        $path = implode('/', $trap['categoryPath']);
        $categoryId = $categoryIdsByPath[$path] ?? null;
        \assert($categoryId !== null, $path);

        $properties = [];
        foreach ($trap['properties'] as $group => $values) {
            foreach ($values as $value) {
                $properties[] = ['id' => $optionIds[$group][$value]];
            }
        }

        $product = [
            'id' => SeedId::forPath('product', $trap['id']),
            'productNumber' => 'FW-' . strtoupper($trap['id']),
            'name' => $trap['name'],
            'description' => $trap['description'],
            'price' => [['currencyId' => \Shopware\Core\Defaults::CURRENCY, 'gross' => $trap['price'], 'net' => $trap['price'], 'linked' => true]],
            'taxId' => $taxId,
            'active' => true,
            'stock' => 6,
            'categories' => [['id' => $categoryId]],
            'properties' => $properties,
        ];

        if ($trap['sizes']) {
            $family = SizeFamily::build($product['id'], $product['productNumber'], $trap['price'], $taxId, $sizeOptionIds, 3);
            $product['children'] = $family['children'];
            $product['configuratorSettings'] = $family['configuratorSettings'];
        }

        return $product;
    }
}
```

`$product['id']` for traps is `SeedId::forPath('product', $trap['id'])`, not the raw fixture id
(`'fw-occ-dress-1'` is not 32 hex chars, so it cannot be a Shopware entity id directly) — the *name* and
*category path* are what must match the fixture (asserted by `FashionSeedTrapsParityTest` and
`ProductPlanTest::testEveryTrapProductIsPresentWithItsFixtureId`, which checks the **fixture** id
`'fw-occ-dress-1'` appears somewhere findable; re-check that assertion against the actual return shape
during implementation — it may need to compare `FashionSeedTraps::all()`'s ids against a derived
`SeedId::forPath('product', ...)` list rather than expecting the raw string in `$plan`'s `id` column).
Fix the test to assert `in_array(SeedId::forPath('product', $trap['id']), $ids, true)` instead, once this
is confirmed during Step 3.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/ProductPlanTest.php`
Expected: PASS, 5 tests. If the sellable-unit count is off, check `ProductFillerBuilder::COUNT` (3,600)
and the `$index % 5` variant-free rule first — that is where the fixture's 720/2,880 split comes from.

- [ ] **Step 5: Run the full Task 1–6 suite together**

Run: `vendor/bin/phpunit tests/Command/Seed/`
Expected: PASS, all tests across all six files. This is the point where a mismatch between
`CategoryTreePlan`'s path keys and `ProductPlan`'s path lookups (both must agree on the exact `/`-joined
string) would surface as an `assert()` failure rather than a silent `null` category id.

- [ ] **Step 6: Commit**

```bash
git add src/Command/Seed/SizeFamily.php src/Command/Seed/ProductFillerBuilder.php \
  src/Command/Seed/ProductPlan.php tests/Command/Seed/ProductPlanTest.php
git commit -m "feat(seed): build the fashion product list as DAL-ready payloads"
```

---

### Task 7: The re-run guard

**Files:**
- Create: `src/Command/Seed/MarkerCategoryStore.php`
- Create: `src/Command/Seed/SeedGuard.php`
- Create: `src/Command/Seed/DalMarkerCategoryStore.php`
- Test: `tests/Command/Seed/SeedGuardTest.php`

**Interfaces:**
- Produces: `MarkerCategoryStore` (interface) — `exists(string $id, Context $context): bool`,
  `create(string $id, string $parentId, string $name, Context $context): void`.
- Produces: `SeedGuard::markerId(): string`, `SeedGuard::alreadySeeded(Context $context): bool`,
  `SeedGuard::markSeeded(string $navigationRootId, Context $context): void`.
- Produces: `DalMarkerCategoryStore` — the only production implementation of `MarkerCategoryStore`,
  backed by `EntityRepository` over `category`. **Not covered by this repo's test suite** — it needs a
  live container; verified manually in Task 9.
- Consumed by: Task 8 (`SeedRunner`, `services.xml`).

This is the one DAL-adjacent piece worth a real unit test: it is the only thing standing between a
second run and a doubled catalogue (Global Constraints: no `--force`), so its branching deserves the
same confidence the pure plan classes get, via a fake implementing the two-method interface rather than
a real `EntityRepository`.

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Seed/SeedGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Command\Seed\MarkerCategoryStore;
use Swag\AssistantStarterKit\Command\Seed\SeedGuard;

/**
 * `Shopware\Core\Framework\Context` is a plain, dependency-free value object (unlike
 * `SalesChannelContext`, `Criteria`, or `SalesChannelProductEntity`, which the seam rule confines to
 * `Core/Commerce/Dal`) — it carries no DAL and needs no container, so it is safe to construct directly
 * in a unit test the way this file does.
 */
final class SeedGuardTest extends TestCase
{
    public function testNotSeededWhenTheMarkerIsAbsent(): void
    {
        $guard = new SeedGuard(new class implements MarkerCategoryStore {
            public function exists(string $id, Context $context): bool
            {
                return false;
            }

            public function create(string $id, string $parentId, string $name, Context $context): void {}
        });

        self::assertFalse($guard->alreadySeeded(Context::createDefaultContext()));
    }

    public function testAlreadySeededWhenTheMarkerExists(): void
    {
        $guard = new SeedGuard(new class implements MarkerCategoryStore {
            public function exists(string $id, Context $context): bool
            {
                return $id === SeedGuard::markerId();
            }

            public function create(string $id, string $parentId, string $name, Context $context): void {}
        });

        self::assertTrue($guard->alreadySeeded(Context::createDefaultContext()));
    }

    public function testMarkSeededCreatesTheMarkerUnderTheGivenParent(): void
    {
        $created = null;
        $guard = new SeedGuard(new class ($created) implements MarkerCategoryStore {
            public function __construct(private mixed &$captured) {}

            public function exists(string $id, Context $context): bool
            {
                return false;
            }

            public function create(string $id, string $parentId, string $name, Context $context): void
            {
                $this->captured = [$id, $parentId, $name];
            }
        });

        $guard->markSeeded('root0000000000000000000000000000', Context::createDefaultContext());

        self::assertSame([SeedGuard::markerId(), 'root0000000000000000000000000000', SeedGuard::MARKER_NAME], $created);
    }

    public function testMarkerIdIsStable(): void
    {
        self::assertSame(SeedGuard::markerId(), SeedGuard::markerId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SeedGuard::markerId());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Seed/SeedGuardTest.php`
Expected: FAIL with "Interface ... MarkerCategoryStore not found" (or similar — `SeedGuard` does not
exist either yet).

- [ ] **Step 3: Write the implementation**

Create `src/Command/Seed/MarkerCategoryStore.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;

/**
 * The two operations {@see SeedGuard} needs from a category repository. A dedicated interface rather
 * than `EntityRepository` directly, so the guard's branching logic — the one piece standing between a
 * re-run and a doubled catalogue — can be unit tested with a fake instead of a live container.
 */
interface MarkerCategoryStore
{
    public function exists(string $id, Context $context): bool;

    public function create(string $id, string $parentId, string $name, Context $context): void;
}
```

Create `src/Command/Seed/SeedGuard.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;

/**
 * The only defence against seeding twice. HANDOFF.md, Task A: *"Do not add `--force`: the recovery for
 * 'I seeded twice' is a database restore either way."* — there is deliberately no override here.
 *
 * The marker category is written **last**, by {@see SeedRunner}, after every category, property group
 * and product write succeeds — so a run that dies partway through leaves no marker, and the next
 * invocation reports "not yet seeded" rather than falsely claiming success. That also means a failed
 * run's partial writes are not cleaned up automatically; recovering from one is the database restore
 * the quote above already names.
 */
final readonly class SeedGuard
{
    public const MARKER_NAME = 'Fashion Seed Marker — do not delete';

    public function __construct(private MarkerCategoryStore $store) {}

    public static function markerId(): string
    {
        return SeedId::forPath('marker', 'fashion-seed');
    }

    public function alreadySeeded(Context $context): bool
    {
        return $this->store->exists(self::markerId(), $context);
    }

    public function markSeeded(string $navigationRootId, Context $context): void
    {
        $this->store->create(self::markerId(), $navigationRootId, self::MARKER_NAME, $context);
    }
}
```

Create `src/Command/Seed/DalMarkerCategoryStore.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * {@see MarkerCategoryStore} over the real `category` repository. Not covered by this repo's own test
 * suite — `EntityRepository` needs a live Shopware container, which `vendor/bin/phpunit` here does not
 * have (Global Constraints). {@see SeedGuardTest} covers the branching logic this class only executes
 * against; this class itself is verified by running the seed command inside the Docker shop.
 */
final readonly class DalMarkerCategoryStore implements MarkerCategoryStore
{
    public function __construct(private EntityRepository $categoryRepository) {}

    public function exists(string $id, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $id));
        $criteria->setLimit(1);

        return $this->categoryRepository->searchIds($criteria, $context)->getTotal() > 0;
    }

    public function create(string $id, string $parentId, string $name, Context $context): void
    {
        // Inactive: the marker must never appear in the storefront's own navigation, only exist for
        // `exists()` to find.
        $this->categoryRepository->create([[
            'id' => $id, 'parentId' => $parentId, 'name' => $name, 'active' => false,
        ]], $context);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Seed/SeedGuardTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Command/Seed/MarkerCategoryStore.php src/Command/Seed/SeedGuard.php \
  src/Command/Seed/DalMarkerCategoryStore.php tests/Command/Seed/SeedGuardTest.php
git commit -m "feat(seed): guard the seeder against a second run"
```

---

### Task 8: The writer, the runner and the command

**Files:**
- Create: `src/Command/Seed/SeedReport.php`
- Create: `src/Command/Seed/SeedWriter.php`
- Create: `src/Command/Seed/SeedRunner.php`
- Create: `src/Command/SeedFashionCatalogueCommand.php`
- Modify: `src/Resources/config/services.xml`

**Interfaces:**
- Produces: `SeedReport` — readonly DTO `{categoryCount: int, propertyGroupCount: int, productCount:
  int, sellableUnits: int}`, rendered by the command at the end.
- Produces: `SeedWriter::writeCategories(SymfonyStyle $io, EntityRepository $repo, array $tree, Context
  $context): void`, `::writePropertyGroups(...)`, `::writeProducts(...)` — the only code in this feature
  that calls `EntityRepository::create()`, batching products at 200 per call and toggling
  `EntityIndexerRegistry::DISABLE_INDEXING` per batch, mirroring
  `vendor/shopware/core/Framework/Demodata/Generator/ProductGenerator.php`'s `write()`.
- Produces: `SeedRunner::run(SymfonyStyle $io, SalesChannelContext $context): SeedReport` — orchestrates
  guard check → tax lookup → plan building (Tasks 4–6) → `SeedWriter` calls → `SeedGuard::markSeeded()`.
  Throws `\RuntimeException` (uncaught, by design — see Global Constraints on not guessing a payload)
  if already seeded or if no tax rule exists.
- Produces: `SeedFashionCatalogueCommand` (`swag:assistant:seed-fashion-catalogue`) — parses
  `--sales-channel` (default: the Storefront lab channel, same constant `ProbeCommand` and
  `BenchmarkCommand` use), builds the `SalesChannelContext` the same way those two do, warns if
  `APP_ENV` is not `prod`, delegates to `SeedRunner`, renders the `SeedReport`.
- Not unit tested (`SeedWriter`, `SeedRunner`, `SeedFashionCatalogueCommand`): all three require a live
  `EntityRepository`/`SalesChannelContext`, which this repo's `vendor/bin/phpunit` cannot construct
  (Global Constraints). Verified manually in Task 9.

- [ ] **Step 1: Write `SeedReport`**

Create `src/Command/Seed/SeedReport.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

final readonly class SeedReport
{
    public function __construct(
        public int $categoryCount,
        public int $propertyGroupCount,
        public int $productCount,
        public int $sellableUnits,
    ) {}
}
```

- [ ] **Step 2: Write `SeedWriter`**

Create `src/Command/Seed/SeedWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only code in this feature that calls `EntityRepository::create()`. Everything upstream (Tasks
 * 1–6) is pure array-building; this class exists so the DAL boundary is exactly one small, unit-test-
 * exempt file, matching how `Core/Commerce/Dal` confines Shopware's own DAL types.
 *
 * `EntityIndexerRegistry::DISABLE_INDEXING` toggled around every write, exactly as
 * `vendor/shopware/core/Framework/Demodata/Generator/{ProductGenerator,CategoryGenerator}.php` do —
 * removing the state after each batch is what re-enables Shopware's normal (queued) indexing for that
 * batch, so no separate reindex step is needed afterward.
 */
final readonly class SeedWriter
{
    private const PRODUCT_BATCH_SIZE = 200;

    /**
     * @param list<array<string, mixed>> $tree
     */
    public function writeCategories(SymfonyStyle $io, EntityRepository $categoryRepository, array $tree, Context $context): void
    {
        $io->writeln('Writing category tree…');
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        $categoryRepository->create($tree, $context);
        $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    public function writePropertyGroups(SymfonyStyle $io, EntityRepository $propertyGroupRepository, array $groups, Context $context): void
    {
        $io->writeln('Writing property groups…');
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        $propertyGroupRepository->create($groups, $context);
        $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    public function writeProducts(SymfonyStyle $io, EntityRepository $productRepository, array $products, Context $context): void
    {
        $io->progressStart(\count($products));

        foreach (array_chunk($products, self::PRODUCT_BATCH_SIZE) as $batch) {
            $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
            $productRepository->create($batch, $context);
            $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
            $io->progressAdvance(\count($batch));
        }

        $io->progressFinish();
    }
}
```

- [ ] **Step 3: Write `SeedRunner`**

Create `src/Command/Seed/SeedRunner.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates the seed: guard check, tax lookup, the three plan builders (Tasks 4–6), the three writes
 * ({@see SeedWriter}), then the marker ({@see SeedGuard::markSeeded()}) — written last, deliberately,
 * so a run that fails partway through is visibly unfinished on the next invocation rather than
 * silently guarded.
 */
final readonly class SeedRunner
{
    public function __construct(
        private SeedGuard $guard,
        private SeedWriter $writer,
        private EntityRepository $categoryRepository,
        private EntityRepository $propertyGroupRepository,
        private EntityRepository $productRepository,
        private Connection $connection,
    ) {}

    public function run(SymfonyStyle $io, SalesChannelContext $salesChannelContext): SeedReport
    {
        $context = $salesChannelContext->getContext();

        if ($this->guard->alreadySeeded($context)) {
            throw new \RuntimeException(
                'This shop already carries the fashion seed marker — refusing to seed twice. '
                . 'Recovery is a database restore, not a re-run (see HANDOFF.md, Task A).',
            );
        }

        $taxId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM tax LIMIT 1');
        if (!\is_string($taxId) || $taxId === '') {
            throw new \RuntimeException('No tax rule exists in this shop — cannot price seeded products.');
        }

        $navigationRootId = $salesChannelContext->getSalesChannel()->getNavigationCategoryId();
        \assert(\is_string($navigationRootId));

        $categoryPlan = CategoryTreePlan::build($navigationRootId);
        $propertyPlan = PropertyGroupPlan::build();
        $products = ProductPlan::build($categoryPlan['idsByPath'], $propertyPlan['optionIds'], $propertyPlan['sizeOptionIds'], $taxId);

        $this->writer->writeCategories($io, $this->categoryRepository, $categoryPlan['tree'], $context);
        $this->writer->writePropertyGroups($io, $this->propertyGroupRepository, $propertyPlan['groups'], $context);
        $this->writer->writeProducts($io, $this->productRepository, $products, $context);

        $this->guard->markSeeded($navigationRootId, $context);

        $sellableUnits = 0;
        foreach ($products as $product) {
            $children = $product['children'] ?? [];
            $sellableUnits += $children !== [] ? \count($children) : 1;
        }

        return new SeedReport(
            categoryCount: \count($categoryPlan['idsByPath']),
            propertyGroupCount: \count($propertyPlan['groups']),
            productCount: \count($products),
            sellableUnits: $sellableUnits,
        );
    }
}
```

- [ ] **Step 4: Write the command**

Create `src/Command/SeedFashionCatalogueCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Swag\AssistantStarterKit\Command\Seed\SeedRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the fashion taxonomy — ~15,200 sellable units across ~1,031 category nodes — into this shop
 * through the DAL. HANDOFF.md, Task A: every fashion-scale number measured so far is a
 * `FixtureCommerceGateway` number; this is what lets the same evals run against real Shopware search.
 *
 * **Dev-only, and deliberately impossible to run twice** — see `Core\Commerce\Command\Seed\SeedGuard`.
 * There is no `--force`: recovering from a mistaken second run is a database restore either way.
 *
 * Same context-scoping pattern as {@see ProbeCommand} and {@see BenchmarkCommand} — a console command
 * has no HTTP request, so `SalesChannelContextProvider::current()` would throw; the context is built
 * here instead. Unlike those two, this command does not need `SalesChannelContextProvider::use()`,
 * because {@see SeedRunner} takes the `SalesChannelContext` directly rather than going through
 * `CommerceGatewayInterface`.
 */
#[AsCommand(
    name: 'swag:assistant:seed-fashion-catalogue',
    description: 'Seed the fashion taxonomy into this shop through the DAL. Dev-only; cannot be run twice.',
)]
final class SeedFashionCatalogueCommand extends Command
{
    /** The Storefront sales channel of the lab environment; overridable for any other shop. */
    private const DEFAULT_SALES_CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function __construct(
        private readonly SeedRunner $runner,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'sales-channel',
            null,
            InputOption::VALUE_REQUIRED,
            'Sales channel whose navigation tree the categories attach under.',
            self::DEFAULT_SALES_CHANNEL,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

        if ($env !== 'prod') {
            // A warning, not a block (Global Constraints) — measurement quality, not correctness.
            $io->warning(\sprintf(
                'APP_ENV is "%s", not "prod". HANDOFF.md: dev mode inflates every number and will make '
                . 'writing 3,617 products slower. Proceeding anyway.',
                \is_string($env) ? $env : 'unset',
            ));
        }

        $salesChannelId = (string) $input->getOption('sales-channel');
        $context = $this->contextFactory->create(Uuid::randomHex(), $salesChannelId);

        try {
            $report = $this->runner->run($io, $context);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf(
            'Seeded %d categories, %d property groups, %d products (%d sellable units).',
            $report->categoryCount,
            $report->propertyGroupCount,
            $report->productCount,
            $report->sellableUnits,
        ));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Register the services**

Modify `src/Resources/config/services.xml` — add beside the existing `BenchmarkCommand` block (after
line 307 in the version read during planning; find the current `</service>` that closes
`Swag\AssistantStarterKit\Command\BenchmarkCommand` and insert after it):

```xml
        <!-- Task A of the fashion-scale-sweep handoff: writes the fashion taxonomy into a real shop
             through the DAL, so the fashion evals can finally run against real Shopware search
             instead of FixtureCommerceGateway. Dev-only; SeedGuard makes a second run refuse itself. -->
        <service id="Swag\AssistantStarterKit\Command\Seed\DalMarkerCategoryStore">
            <argument type="service" id="category.repository"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\Seed\SeedGuard">
            <argument type="service" id="Swag\AssistantStarterKit\Command\Seed\DalMarkerCategoryStore"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\Seed\SeedWriter"/>

        <service id="Swag\AssistantStarterKit\Command\Seed\SeedRunner">
            <argument type="service" id="Swag\AssistantStarterKit\Command\Seed\SeedGuard"/>
            <argument type="service" id="Swag\AssistantStarterKit\Command\Seed\SeedWriter"/>
            <argument type="service" id="category.repository"/>
            <argument type="service" id="property_group.repository"/>
            <argument type="service" id="product.repository"/>
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\SeedFashionCatalogueCommand">
            <argument type="service" id="Swag\AssistantStarterKit\Command\Seed\SeedRunner"/>
            <argument type="service" id="Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory"/>
            <tag name="console.command"/>
        </service>
```

`Swag\AssistantStarterKit\Command\Seed\MarkerCategoryStore` (the interface) needs no `<service>` entry —
`DalMarkerCategoryStore`'s registration above is enough for `SeedGuard`'s constructor argument to
resolve, since PHP's autowiring is not in play here (every argument is spelled out explicitly, matching
`ProbeCommand`'s and `BenchmarkCommand`'s registrations) and `SeedGuard` is wired directly against the
concrete `DalMarkerCategoryStore` service id, not the interface. If this project's `services.xml` turns
out to alias interfaces to implementations elsewhere (check for a pattern like the
`CommerceGatewayInterface` alias at line ~311), prefer that convention instead — alias
`MarkerCategoryStore` to `DalMarkerCategoryStore` and wire `SeedGuard` against the interface id, for
consistency with how this codebase treats every other seam.

- [ ] **Step 6: Run the whole repo's deterministic suite**

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: previous count **+ 19 new test files' worth of tests**, still fully green, 0 failures. This
confirms the new `src/Command/Seed/*` and `src/Command/SeedFashionCatalogueCommand.php` files at least
parse and autoload cleanly (PHP's autoloader will choke on a syntax error in
`SeedFashionCatalogueCommand.php` or `SeedRunner.php`/`SeedWriter.php` even though nothing directly
tests them, since PHPUnit's own class-loading may touch them via other Command-namespace tests).

- [ ] **Step 7: Commit**

```bash
git add src/Command/Seed/SeedReport.php src/Command/Seed/SeedWriter.php src/Command/Seed/SeedRunner.php \
  src/Command/SeedFashionCatalogueCommand.php src/Resources/config/services.xml
git commit -m "feat(seed): wire the fashion catalogue seeder command"
```

---

### Task 9: Run `composer run quality`, fix what it finds

**Files:** none specified — this task fixes whatever the gate reports in the files Tasks 1–8 created.

- [ ] **Step 1: Format**

Run: `composer run format` (rewrites; `format:check` alone would only report).
Expected: reflows the new files to Mago's style. Re-read any file you are about to quote or diff after
this step — the formatter changes line numbers.

- [ ] **Step 2: Lint and typecheck**

Run: `composer run lint` then `composer run typecheck`.
Expected: both exit 0. Likely findings given this plan's own code above, to fix as they surface rather
than pre-guessed: `PropertyGroupPlan::build()`'s `$sizeOptionIdsAsGroupShape` and `$ignored` placeholder
noted in Task 5 Step 3 (must be a real, used variable — resolve it there, not here); an unused `$namePart`
parameter in `CategoryTreePlan::register()` (noted in Task 4 Step 3). `mago analyze` sees real
`shopware/core` classes from this repo's own `vendor/` (Global Constraints), not a stub, so a class-not-
found finding here would mean a genuinely wrong import, not a missing stub entry.

- [ ] **Step 3: File length and duplication**

Run: `composer run quality:filesize` then `composer run quality:dupes`.
Expected: every new file under 400 lines (Tasks 1–8 were sized against this deliberately —
`ProductFillerBuilder.php` and `CategoryTreePlan.php` are the two to check first if either fails); no
new duplication findings between `SizeFamily.php` and any fixture class (it is *meant* to look similar
to `FashionCatalogGenerator::sizeFamily()` — if `jscpd` flags it, that is an acceptable, known
duplication given the two live in different deployment contexts, same posture as
`FashionSeedTaxonomy`/`FashionSeedTraps` themselves being deliberate, tested duplicates of the fixture
classes).

- [ ] **Step 4: Full quality gate and full deterministic suite**

Run: `composer run quality` then `vendor/bin/phpunit --exclude-group eval`.
Expected: both exit 0.

- [ ] **Step 5: Commit any fixes**

```bash
git add -u
git commit -m "fix(seed): quality gate findings"
```

(Skip this step entirely if Steps 1–4 found nothing to change.)

---

### Task 10: Seed the real shop and verify — manual, not automated

This task has no PHPUnit test: it is the reason Tasks 7–8 exist, and it runs `SeedWriter`/`SeedRunner`/
`SeedFashionCatalogueCommand` for the first time against a live container, which this repo's test suite
structurally cannot do (Global Constraints). Follow `HANDOFF.md`'s environment section for exact
commands; this task sequences them for this specific feature.

- [ ] **Step 1: Back up before anything else**

```bash
docker compose exec -T database sh -lc 'mariadb-dump -uroot -proot shopware' > ~/shopware-before-fashion-seed.sql
```

Confirm the file is non-trivial in size (`ls -lh ~/shopware-before-fashion-seed.sql`) before proceeding
— an empty or truncated dump is not a backup.

- [ ] **Step 2: Confirm the branch the container is running**

```bash
git status   # in this repo — confirm you're on integration/fashion-scale-sweep with Task 1–9 committed
docker compose exec -T web sh -lc 'cd /var/www/html/plugin-src && git rev-parse HEAD'   # must match
```

- [ ] **Step 3: Clear the cache, run the command**

```bash
docker compose exec -T web sh -lc 'php bin/console cache:clear --no-warmup'
docker compose exec -T web sh -lc 'APP_ENV=prod php bin/console swag:assistant:seed-fashion-catalogue'
```

Expected: a progress bar for the product write, then `[OK] Seeded 1031 categories, 4 property groups,
3617 products (15201 sellable units).` If it fails partway through, **do not re-run it** — the guard
will refuse a second attempt regardless, but the honest recovery per Global Constraints is: `docker
compose exec -T database sh -lc 'mariadb -uroot -proot shopware' < ~/shopware-before-fashion-seed.sql`,
then re-open this task after fixing whatever the failure pointed at.

- [ ] **Step 4: Confirm the guard actually guards**

```bash
docker compose exec -T web sh -lc 'php bin/console swag:assistant:seed-fashion-catalogue'
```

Expected: non-zero exit, the "refusing to seed twice" message, no further writes.

- [ ] **Step 5: Confirm the vocabulary block still lacks category names**

This is the measurement the whole HANDOFF.md report chain has been waiting on.

```bash
docker compose exec -T web sh -lc 'APP_ENV=prod php bin/console swag:assistant:probe --facets --sales-channel=01a01b4af6567284ac9eeb3616598ac3'
```

Expected: `properties`/`options`/`manufacturer.name` fields as before seeding — **still no category
field**, confirming `DalCommerceGateway::facets()` was never the thing that changed; only the catalogue
behind it did.

- [ ] **Step 6: Spot-check the two divergences named in Context**

```bash
docker compose exec -T web sh -lc 'APP_ENV=prod php bin/console swag:assistant:probe --search="Occasion Suits" --sales-channel=01a01b4af6567284ac9eeb3616598ac3'
docker compose exec -T web sh -lc 'APP_ENV=prod php bin/console swag:assistant:probe --search=dress --sales-channel=01a01b4af6567284ac9eeb3616598ac3'
```

Record what comes back — this is Finding-shaped output for whoever writes the follow-up report, not a
pass/fail check this plan can pre-specify. In particular: does "Occasion Suits" now return suits (real
Shopware search, unlike `FixtureTermMatcher`, stems), and does `dress` come back with a real, exact
match count rather than the fixture's capped one?

- [ ] **Step 7: Re-run the fashion eval suite against the seeded shop**

Per `HANDOFF.md`:

```bash
ASSISTANT_EVAL_CATALOG=fashion ASSISTANT_LLM_MODEL=google/gemini-3.7-flash vendor/bin/phpunit --group eval --testdox --filter fashion_
```

Note this still runs against `FixtureCommerceGateway` (`ASSISTANT_EVAL_CATALOG` selects the *fixture*
catalogue for the eval harness — it has no lever to point at the seeded real shop instead). Recording
its result here is for comparison only; it is not evidence about the real shop. The actual real-shop
behaviour is Steps 5–6 above, and `swag:assistant:probe --ask "<question>"` (per `ProbeCommand`) or the
HTTP `/assistant/chat` endpoint (per `HANDOFF.md`'s note that `ProbeTurnRunner` cannot see
container-tagged tools) for anything that needs a full model turn.

- [ ] **Step 8: Write the follow-up report**

Not part of this plan's code, but the next deliverable per `HANDOFF.md`'s "What to measure once it
exists" — a new `docs/superpowers/reports/YYYY-MM-DD-fashion-catalogue-seeded.md` covering: the
`MatchCountReader` latency at 15,201 real units, `DalCategoryTreeReader`'s two queries at 1,031 real
categories (and whether `MAX_NODES = 40` truncates anything at the top level, per its own docblock's
"not yet measured against a shop with a wide level"), and whether `fashion_wedding_occasion`'s
`rendered_ids_from_each` — red on the fixture for the stemming reason in Context — passes once "Occasion
Suits" is a real Shopware search against a real index.

---

## Self-review

**Spec coverage** (against `HANDOFF.md`'s Task A):

- "console command that writes ~15,000 products across ~1,000 categories" — Task 8 (command), Tasks 4/6
  (1,031 categories / 3,617 products, 15,201 units).
- "matching the fixture's taxonomy" — Tasks 1–2 (duplication + parity tests).
- "tests/ is not autoloaded... duplicate the word lists and assert the duplication away" — Tasks 1–2
  directly; `PropertyGroupPlan` (Task 5) explicitly does *not* duplicate the fixture's colour/material
  lists and states why, rather than silently diverging.
- "Do not guess a payload" — every write payload in Tasks 4–8 is traced to a specific field a specific
  reader (`DalCriteriaBuilder`, `DalCategoryTreeReader`, `DalCommerceGateway::facets()`,
  `DalProductCardMapper`) actually consumes, or to `ProductGenerator`/`CategoryGenerator`/
  `PropertyGroupGenerator`'s own verified-correct payload shape.
- "Guard against running twice... a marker category and a non-zero exit is enough... Do not add
  `--force`" — Task 7 (`SeedGuard`), Task 8's command has no such option.
- "mariadb-dump before seeding. APP_ENV=prod when seeding and when measuring" — Task 10 Steps 1 and 3;
  the command warns rather than blocks on `APP_ENV` (Global Constraints explains why a block would be
  the wrong shape here).
- "Does the vocabulary block still lack category names?" — Task 10 Step 5.
- "Re-run the whole eval suite against the seeded shop... Expect Occasion Suits to behave differently" —
  Task 10 Steps 6–7, with the caveat spelled out that the automated eval suite has no lever onto the
  real shop.
- "MatchCountReader... report the latency" / "DalCategoryTreeReader's two queries... whether MAX_NODES
  truncates anything" — Task 10 Step 8, correctly scoped as the follow-up report's job, not this plan's
  code.

**Placeholder scan:** two spots in this plan intentionally hand the implementer a specific, named fix
rather than finished code — `CategoryTreePlan::register()`'s unused `$namePart` parameter (Task 4 Step
3) and `PropertyGroupPlan::build()`'s `$sizeOptionIdsAsGroupShape` throwaway (Task 5 Step 3). Both name
the exact change required and why; neither is a "TBD" or "add appropriate handling". Left this way
deliberately: writing the fully-corrected version in the plan and then explaining the correction would
duplicate the explanation for no benefit, and the parity/unit tests in the same task catch either being
missed.

**Type consistency:** `ProductPlan::build()`'s three array parameters (`$categoryIdsByPath`,
`$optionIds`, `$sizeOptionIds`) match `CategoryTreePlan::build()['idsByPath']` and
`PropertyGroupPlan::build()['optionIds' | 'sizeOptionIds']`'s exact shapes throughout Tasks 4–6.
`SizeFamily::build()`'s signature is identical at both call sites (`ProductPlan::trapToPayload()` and
`ProductFillerBuilder::build()`). `SeedGuard`'s constructor argument and `services.xml`'s registration in
Task 8 both reference `DalMarkerCategoryStore` as the concrete id (Task 7 built no interface alias) —
flagged as an explicit, checkable choice in Task 8 Step 5 rather than left inconsistent with the rest of
the codebase's seam convention without comment.
