# Catalogue-Scale Robustness, Phase B Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only `swag:assistant:benchmark` console command that reports what the assistant costs against a real Shopware catalogue of ~10,000 products — facet-probe milliseconds, search milliseconds at p50/p95, the vocabulary block's field and value counts, prompt characters, retrieve hit counts, and the cards endpoint's per-id lookup count — as a table nobody has to interpret.

**Architecture:** A thin command that builds a sales-channel context and delegates, exactly as `ProbeCommand` delegates to `ProbeTurnRunner`. All measurement lives in `BenchmarkRunner`, which sees only the gateway seam and the layers above it, so it is unit-testable against `FixtureCommerceGateway` with no database. The measured shop is a `framework:demodata`-seeded copy of the local dev shop, snapshotted before and restored after.

**Tech Stack:** PHP 8.2 target (running on 8.5), PHPUnit 11, Symfony Console, Mago (format + lint + analyze), Shopware 6.7.13, MariaDB 11.8 in `docker compose`.

**Spec:** `docs/superpowers/specs/2026-08-25-catalogue-scale-robustness-design.md` — read it first, especially *Phase B, in outline* and its *Handoff for whoever plans B*.

**Phase A's report:** `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`. Read *Findings* and *What this does not say* — the spec instructs B's planner to, and it is why Task 6 measures a fixed query list rather than sampling.

## What changed since the spec was written

Phase A ran, and one of its findings has already been fixed (commit `4d16569`): the vocabulary block
used to degrade to **zero fields** on any catalogue with 30 or more property groups, because
`CatalogVocabularyBudget` walked a single shared value cap to 0 and a field with no values is dropped
entirely. It now falls back to dropping whole fields instead.

**Why that matters to this plan.** The spec's *Handoff* says B's vocabulary column should record
"field count / value count / truncation flag". Before the fix, every seeded shop with 30+ groups would
have reported `0 / 0 / true` and the column would have measured the defect rather than the shop. It
now reports something real. The demodata seed in Task 6 uses `--properties=100`, which is comfortably
past `MAX_FIELDS` (30), so this column exercises the new fallback path against a real aggregation —
the first time that code meets anything but a fixture.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Mago analyze runs at full strictness — no `mixed`, no unsafe casts.
- PHP 8.2 target. Cyclomatic complexity ≤ 10, nesting depth ≤ 4, ≤ 5 parameters, ~400 lines per file.
- **Mago's thresholds are per class, and they are errors, not warnings.** `composer run quality` fails on `error[…]` only; the repo carries ~90 lint and ~250 analyze warnings as its normal state, so judge a run by `grep 'error\['`, not by the issue total. Two thresholds bit repeatedly in Phase A and are **not** written in `mago.toml`:
  - `too-many-methods` fires at **11 methods per class**, test classes included.
  - `cyclomatic-complexity` (threshold 10) is summed **across every method of a class**, so moving branches into another method of the same class does not help — only genuinely fewer branches, or a second class.
- Declare array shapes once with `@phpstan-type` and pull them in with `@phpstan-import-type`; both work. `array<string, mixed>` anywhere makes every downstream field access `mixed` and produces dozens of analyze errors.
- PHPUnit's `assertNotNull()` does **not** narrow a nullable for the analyzer. Give tests a lookup that throws instead.
- Run `composer run format` **before** staging. `mago fmt` collapses multi-line calls, and the `pre-commit` hook runs `mago fmt --check` plus `mago lint` on staged PHP and will reject the commit. The hook never runs `mago analyze`, so run `composer run quality` before believing a commit is clean.
- Throw `Throwable` subclasses only; preserve `$previous` when wrapping.
- No `echo`/`var_dump`/`print_r`/`dd` in `src/`. Mago's `no-debug-symbols` blocks them — but `SymfonyStyle` output in a command is fine, that is the command's job.
- **The gateway seam rule holds.** `CommerceGatewayInterface`'s docblock says only DTOs from `Dto\` may cross it — never a Shopware entity, never `SalesChannelContext`. `BenchmarkRunner` and everything under `src/Command/Benchmark/` obey it; only `BenchmarkCommand` itself may name a Shopware type.
- **Read-only.** This command must never write to the shop: no `addToCart`, no entity writes, no cache clears. It is a measurement.
- **No assertions on latency.** A millisecond figure is not a pass or a fail (spec, *Phase B in outline*). Tests here assert the machinery — percentile arithmetic, input parsing, call counting, rendering — never a duration.

## The one decision the spec left open

**Where the large shop lives: snapshot, seed, measure, restore.** The spec's recommended option, taken
as-is. `framework:demodata` **adds** rather than replaces, so seeding in place would leave the local
shop's `Trail Jersey` intact but its search ranking and vocabulary block permanently changed, and that
shop is the reference `tests/e2e` and every storefront check depend on. A `mysqldump` either side is one
extra step and makes the measurement repeatable.

**Never the staging shop** (spec decision S8). Its `fx-*` catalogue and eleven engineered traps are what
make the storefront work testable; demodata would bury them without deleting a thing, which is the worst
of both outcomes.

## File Structure

| File | Responsibility |
|---|---|
| `src/Command/Benchmark/Timings.php` (create) | A list of millisecond samples, and the percentiles over it. Pure arithmetic, no clock. |
| `src/Command/Benchmark/CountingGateway.php` (create) | A `CommerceGatewayInterface` decorator that counts calls per method and forwards. How the cards endpoint's per-id lookups get counted without a SQL profiler. |
| `src/Command/Benchmark/BenchmarkReport.php` (create) | The collected numbers as one immutable value. What the renderer prints and what a test asserts against. |
| `src/Command/Benchmark/QueryMeasurement.php` (create) | One term's timings, its retrieve hit count, and what it narrowed to. |
| `src/Command/Benchmark/VocabularyMeasurement.php` (create) | Vocabulary available vs sent, the truncation flag, vocabulary and prompt characters. |
| `src/Command/Benchmark/FacetProbeMeasurement.php` (create) | The facet probe cold and warm. |
| `src/Command/Benchmark/CardEndpointMeasurement.php` (create) | Ids requested, catalogue lookups performed, total milliseconds. |
| `src/Command/Benchmark/BenchmarkRunner.php` (create) | Does the measuring. Sees only the gateway seam and the layers above it, so it runs against `FixtureCommerceGateway` in a unit test and against the DAL in production. |
| `src/Command/Benchmark/BenchmarkRenderer.php` (create) | `BenchmarkReport` → tables on a `SymfonyStyle`. Separate so the numbers can be asserted without parsing console output. |
| `src/Command/BenchmarkRequest.php` (create) | Parses and validates `InputInterface` into a value object. Mirrors `ProbeRequest`. |
| `src/Command/BenchmarkCommand.php` (create) | The CLI surface: builds a `SalesChannelContext`, scopes it through `SalesChannelContextProvider::use()`, delegates to the runner, hands the report to the renderer. The only new file allowed to name a Shopware type. |
| `src/Resources/config/services.xml` (modify, near line 246) | Register the command and the runner; tag `console.command`. |
| `tests/Command/Benchmark/TimingsTest.php` (create) | Percentile arithmetic, including the degenerate cases. |
| `tests/Command/Benchmark/CountingGatewayTest.php` (create) | That every method forwards and is counted exactly once. |
| `tests/Command/Benchmark/BenchmarkRunnerTest.php` (create) | The runner against `FixtureCommerceGateway`: shape of the report, the N+1 count, no writes. |
| `tests/Command/Benchmark/BenchmarkRendererTest.php` (create) | That every measured number reaches the output. |
| `tests/Command/BenchmarkRequestTest.php` (create) | Input parsing and rejection. |
| `docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md` (create) | The numbers. Task 6's deliverable. |

`src/Command/Benchmark/` is a new sub-namespace rather than five more files in the flat `src/Command/`,
which already holds six. It follows `Core/Trace/Export/` and `Core/Commerce/Dal/`.

`BenchmarkCommand` stays thin because it cannot be unit tested: it needs
`AbstractSalesChannelContextFactory`, and this repo has no kernel-booting test infrastructure — every
existing test is a unit test. Keeping the logic in `BenchmarkRunner` is the same split `ProbeCommand` and
`ProbeTurnRunner` already use, and for the same reason.

---

### Task 1: Percentiles

**Files:**
- Create: `src/Command/Benchmark/Timings.php`
- Test: `tests/Command/Benchmark/TimingsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Timings::add(float $milliseconds): void`
  - `Timings::count(): int`
  - `Timings::percentile(float $fraction): float`
  - `Timings::p50(): float`, `Timings::p95(): float`
  - `Timings::mean(): float`, `Timings::max(): float`

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Benchmark/TimingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\Timings;

/**
 * The one piece of this command with a right answer, so it is the one piece with real assertions.
 * Everything else it measures is a duration, and a duration is not a pass (spec, *Phase B in
 * outline*) — which makes getting the arithmetic provably right the only correctness this command
 * can offer.
 */
final class TimingsTest extends TestCase
{
    public function testPercentilesUseNearestRankOverASortedSample(): void
    {
        $timings = new Timings();

        // Added out of order on purpose: a percentile that depended on insertion order would be
        // wrong in exactly the way a benchmark never reveals, because the numbers still look
        // plausible.
        foreach ([50, 10, 100, 30, 90, 20, 80, 40, 70, 60] as $sample) {
            $timings->add((float) $sample);
        }

        self::assertSame(10, $timings->count());
        self::assertSame(50.0, $timings->p50());
        self::assertSame(100.0, $timings->p95());
        self::assertSame(100.0, $timings->max());
        self::assertSame(55.0, $timings->mean());
    }

    public function testP95OfTwentySamplesIsTheNineteenthSmallest(): void
    {
        $timings = new Timings();

        for ($i = 1; $i <= 20; ++$i) {
            $timings->add((float) $i);
        }

        // ceil(0.95 * 20) = 19, so the 19th smallest. Stated as a test because "which of the two
        // neighbouring samples is p95" is the part of percentile arithmetic people get wrong.
        self::assertSame(19.0, $timings->p95());
        self::assertSame(10.0, $timings->p50());
    }

    public function testASingleSampleIsEveryPercentile(): void
    {
        $timings = new Timings();
        $timings->add(7.5);

        self::assertSame(7.5, $timings->p50());
        self::assertSame(7.5, $timings->p95());
        self::assertSame(7.5, $timings->mean());
    }

    /** An empty run must report zero rather than divide by it. */
    public function testNoSamplesReportsZeroRatherThanFailing(): void
    {
        $timings = new Timings();

        self::assertSame(0, $timings->count());
        self::assertSame(0.0, $timings->p50());
        self::assertSame(0.0, $timings->p95());
        self::assertSame(0.0, $timings->mean());
        self::assertSame(0.0, $timings->max());
    }

    public function testAFractionOutsideZeroToOneIsRejected(): void
    {
        $timings = new Timings();
        $timings->add(1.0);

        $this->expectException(\InvalidArgumentException::class);

        $timings->percentile(1.5);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Benchmark/TimingsTest.php`
Expected: FAIL — `Class "Swag\AssistantStarterKit\Command\Benchmark\Timings" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Command/Benchmark/Timings.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * Millisecond samples and the percentiles over them.
 *
 * **No clock in here.** The caller measures and hands the number over, which is what makes the
 * arithmetic testable with literals instead of with sleeps — a timing test that sleeps is slow and
 * flaky, and this project's deterministic suite is neither.
 *
 * Nearest-rank percentiles, not interpolated ones: `ceil(fraction × count)`, then that element of the
 * sorted sample. Every value reported is therefore a real measurement that actually happened, rather
 * than an average of two that did. For a benchmark whose whole purpose is "how slow does this get",
 * an interpolated p95 that no request ever experienced is the wrong kind of tidy.
 */
final class Timings
{
    /** @var list<float> */
    private array $samples = [];

    public function add(float $milliseconds): void
    {
        $this->samples[] = $milliseconds;
    }

    public function count(): int
    {
        return \count($this->samples);
    }

    public function p50(): float
    {
        return $this->percentile(0.50);
    }

    public function p95(): float
    {
        return $this->percentile(0.95);
    }

    /**
     * @throws \InvalidArgumentException when `$fraction` is outside 0..1
     */
    public function percentile(float $fraction): float
    {
        if ($fraction < 0.0 || $fraction > 1.0) {
            throw new \InvalidArgumentException(
                \sprintf('A percentile fraction must be between 0 and 1, got %F.', $fraction),
            );
        }

        if ([] === $this->samples) {
            return 0.0;
        }

        $sorted = $this->samples;
        sort($sorted);

        $rank = (int) ceil($fraction * \count($sorted)) - 1;

        return $sorted[max(0, min($rank, \count($sorted) - 1))];
    }

    public function mean(): float
    {
        if ([] === $this->samples) {
            return 0.0;
        }

        return array_sum($this->samples) / \count($this->samples);
    }

    public function max(): float
    {
        return [] === $this->samples ? 0.0 : max($this->samples);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Benchmark/TimingsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
composer run format
composer run quality
git add src/Command/Benchmark/Timings.php tests/Command/Benchmark/TimingsTest.php
git commit -m "feat(benchmark): nearest-rank percentiles over millisecond samples"
```

---

### Task 2: Counting gateway calls

**Files:**
- Create: `src/Command/Benchmark/CountingGateway.php`
- Test: `tests/Command/Benchmark/CountingGatewayTest.php`

**Interfaces:**
- Consumes: `CommerceGatewayInterface` (existing).
- Produces:
  - `CountingGateway::__construct(CommerceGatewayInterface $inner)`
  - `CountingGateway::callsTo(string $method): int`
  - `CountingGateway::totalCalls(): int`
  - implements every `CommerceGatewayInterface` method by counting and forwarding.

**Why a decorator rather than a SQL profiler.** The spec asks for the cards endpoint's cost in
*queries*, not just milliseconds, because `AssistantCardController` performs one catalogue lookup per
id ([src/Controller/AssistantCardController.php:70](src/Controller/AssistantCardController.php#L70))
and that is why `CardIdList::MAX_IDS` is 12. Counting at the seam gets that exactly: each
`product()` call is one DAL round trip for this endpoint. Counting actual SQL would mean registering a
DBAL middleware through a compiler pass — real work, and it would report Shopware's own framework
queries mixed in with the assistant's, which is not the number the spec asked for.

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Benchmark/CountingGatewayTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\CountingGateway;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * The N+1 the cards endpoint performs is the one cost in this benchmark that is a COUNT rather than a
 * duration, so it is the one that can be asserted. See the task note on why this counts at the
 * gateway seam instead of at the SQL layer.
 */
final class CountingGatewayTest extends TestCase
{
    private static function gateway(): CountingGateway
    {
        return new CountingGateway(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'),
        );
    }

    public function testItForwardsSearchAndReturnsTheInnerResult(): void
    {
        $gateway = self::gateway();

        $cards = $gateway->search(new ProductQuery(term: 'Trail Jersey'), new CatalogScope());

        self::assertNotEmpty($cards, 'the decorator must forward, not swallow');
        self::assertSame(1, $gateway->callsTo('search'));
    }

    public function testItCountsOneCallPerLookupWhichIsTheCardsEndpointCost(): void
    {
        $gateway = self::gateway();
        $scope = new CatalogScope();

        // Exactly what AssistantCardController does: one lookup per requested id.
        foreach (['fx-001', 'fx-004', 'fx-007'] as $id) {
            $gateway->product($id, $scope);
        }

        self::assertSame(3, $gateway->callsTo('product'));
        self::assertSame(3, $gateway->totalCalls());
    }

    public function testAMethodNeverCalledCountsZero(): void
    {
        self::assertSame(0, self::gateway()->callsTo('resolveVariant'));
        self::assertSame(0, self::gateway()->totalCalls());
    }

    public function testFacetsIsForwardedAndCounted(): void
    {
        $gateway = self::gateway();

        $facets = $gateway->facets(new CatalogScope());

        self::assertNotSame([], $facets->facets);
        self::assertSame(1, $gateway->callsTo('facets'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Benchmark/CountingGatewayTest.php`
Expected: FAIL — `Class "…\CountingGateway" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Command/Benchmark/CountingGateway.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Counts calls to each gateway method and forwards them untouched.
 *
 * Exists for one number the spec asks for that is a count rather than a duration: the cards endpoint
 * performs one catalogue lookup **per id**, which is why `CardIdList::MAX_IDS` is 12. Counting at the
 * seam measures the assistant's own round trips; counting SQL would need a DBAL middleware and would
 * mix in every query Shopware's framework makes for its own reasons.
 *
 * `addToCart()` is forwarded like everything else rather than blocked, because a decorator that
 * silently changes behaviour is a worse thing to own than a rule the caller keeps: the benchmark is
 * read-only because {@see BenchmarkRunner} never calls it, and
 * {@see BenchmarkRunnerTest::testItNeverWritesToTheShop} is what holds that.
 */
final class CountingGateway implements CommerceGatewayInterface
{
    /** @var array<string, int> */
    private array $calls = [];

    public function __construct(
        private readonly CommerceGatewayInterface $inner,
    ) {}

    public function callsTo(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function totalCalls(): int
    {
        return array_sum($this->calls);
    }

    public function facets(CatalogScope $scope): FacetSet
    {
        $this->count('facets');

        return $this->inner->facets($scope);
    }

    /** @return list<ProductCard> */
    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        $this->count('search');

        return $this->inner->search($query, $scope);
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        $this->count('product');

        return $this->inner->product($productId, $scope);
    }

    /** @param list<VariantSelection> $selections */
    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        $this->count('resolveVariant');

        return $this->inner->resolveVariant($parentId, $selections, $scope);
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        $this->count('addToCart');

        return $this->inner->addToCart($variantId, $quantity);
    }

    public function cart(): CartSummary
    {
        $this->count('cart');

        return $this->inner->cart();
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Benchmark/CountingGatewayTest.php`
Expected: PASS (4 tests).

Note the method count: 10 including the constructor and `count()`, which is exactly at the limit —
`too-many-methods` fires at 11. Do not add a helper to this class; if another counter is needed, it
belongs in the runner.

- [ ] **Step 5: Commit**

```bash
composer run format
composer run quality
git add src/Command/Benchmark/CountingGateway.php tests/Command/Benchmark/CountingGatewayTest.php
git commit -m "feat(benchmark): count gateway calls to size the cards endpoint's N+1"
```

---

### Task 3: The report value

**Files:**
- Create: `src/Command/Benchmark/BenchmarkReport.php`
- Create: `src/Command/Benchmark/QueryMeasurement.php`
- Create: `src/Command/Benchmark/VocabularyMeasurement.php`
- Create: `src/Command/Benchmark/FacetProbeMeasurement.php`
- Create: `src/Command/Benchmark/CardEndpointMeasurement.php`
- Test: covered by Task 4's renderer test and Task 5's runner test — this task adds no test of its own, and that is deliberate: it is a value object with no behaviour beyond construction, and a test asserting that a readonly property returns what was passed to it tests PHP, not this code.

**Interfaces:**
- Consumes: `Timings` (Task 1).
- Produces:
  - `BenchmarkReport::__construct(string $shopLabel, int $vocabularyFields, int $vocabularyValues, bool $vocabularyTruncated, int $vocabularyChars, int $promptChars, float $facetProbeLiveMs, float $facetProbeCachedMs, array $queries, int $cardLookupCalls, float $cardLookupMs)`
  - `BenchmarkReport::$queries` is `list<QueryMeasurement>`
  - `QueryMeasurement::__construct(string $term, Timings $searchMs, int $retrieveHits, int $narrowedTo)`

- [ ] **Step 1: Write the implementation**

Create `src/Command/Benchmark/BenchmarkReport.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * Everything one benchmark run measured, as one immutable value.
 *
 * A value object rather than an array so the renderer and the tests agree on the field names by
 * construction — an `array<string, mixed>` here would make every field access `mixed` downstream and
 * fail this project's analyzer, and a typo in a key would be a runtime surprise in a command whose
 * whole output is those keys.
 */
final class BenchmarkReport
{
    /** @param list<QueryMeasurement> $queries */
    public function __construct(
        public readonly string $shopLabel,
        public readonly VocabularyMeasurement $vocabulary,
        public readonly FacetProbeMeasurement $facetProbe,
        public readonly array $queries,
        public readonly CardEndpointMeasurement $cards,
    ) {}
}
```

Create `src/Command/Benchmark/QueryMeasurement.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * One shopper-shaped query, measured.
 *
 * `retrieveHits` is what the `retrieve` trace event records as `hits`, and `narrowedTo` is what
 * `retrieve.narrow` records — the two are different numbers at scale, which is the point. Phase A
 * found that `SearchProductsTool` reports `total => count($returned)`, so the size of the shortlist
 * is the only count that reaches the model; keeping both here is what makes the gap visible in a
 * table.
 */
final class QueryMeasurement
{
    public function __construct(
        public readonly string $term,
        public readonly Timings $searchMs,
        public readonly int $retrieveHits,
        public readonly int $narrowedTo,
    ) {}
}
```

Create `src/Command/Benchmark/VocabularyMeasurement.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * What the vocabulary block became on this shop, and what the whole prompt cost.
 *
 * `fields` and `values` are what `CatalogVocabulary::renderWithStats()` returns after
 * `CatalogVocabularyBudget` has cut; `availableFields` and `availableValues` are what the probe found
 * before it. The pair is the measurement — one number alone cannot show how much of a real
 * catalogue's vocabulary the model is actually told about.
 */
final class VocabularyMeasurement
{
    public function __construct(
        public readonly int $availableFields,
        public readonly int $availableValues,
        public readonly int $fields,
        public readonly int $values,
        public readonly bool $truncated,
        public readonly int $vocabularyChars,
        public readonly int $promptChars,
    ) {}
}
```

Create `src/Command/Benchmark/FacetProbeMeasurement.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * The facet probe, measured cold and warm.
 *
 * Both, because `FacetProbe` records `source: live` or `source: cache` on its `facet.probe` trace
 * event and the two differ by orders of magnitude on a real catalogue — reporting one number would
 * describe either the first turn of a conversation or every later one, and never say which.
 */
final class FacetProbeMeasurement
{
    public function __construct(
        public readonly float $liveMs,
        public readonly float $cachedMs,
    ) {}
}
```

Create `src/Command/Benchmark/CardEndpointMeasurement.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * The cards endpoint's cost for one full request.
 *
 * `lookups` is the count the spec asked for: `AssistantCardController` performs one catalogue lookup
 * per id, and `CardIdList::MAX_IDS` is 12 because of it. Measured at the maximum, since that is the
 * request the storefront actually sends when a turn renders a full row.
 */
final class CardEndpointMeasurement
{
    public function __construct(
        public readonly int $ids,
        public readonly int $lookups,
        public readonly float $totalMs,
    ) {}
}
```

- [ ] **Step 2: Verify the analyzer accepts the shapes**

Run: `vendor/bin/mago analyze src/Command/Benchmark/`
Expected: `No issues found.` A readonly-promoted constructor with no body needs no
`check-property-initialization` exemption.

- [ ] **Step 3: Commit**

```bash
composer run format
git add src/Command/Benchmark/
git commit -m "feat(benchmark): the measured numbers as typed values, not arrays"
```

---

### Task 4: Rendering the tables

**Files:**
- Create: `src/Command/Benchmark/BenchmarkRenderer.php`
- Test: `tests/Command/Benchmark/BenchmarkRendererTest.php`

**Interfaces:**
- Consumes: `BenchmarkReport` and its four measurement values (Task 3), `Timings` (Task 1).
- Produces: `BenchmarkRenderer::render(SymfonyStyle $io, BenchmarkReport $report): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Command/Benchmark/BenchmarkRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRenderer;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkReport;
use Swag\AssistantStarterKit\Command\Benchmark\CardEndpointMeasurement;
use Swag\AssistantStarterKit\Command\Benchmark\FacetProbeMeasurement;
use Swag\AssistantStarterKit\Command\Benchmark\QueryMeasurement;
use Swag\AssistantStarterKit\Command\Benchmark\Timings;
use Swag\AssistantStarterKit\Command\Benchmark\VocabularyMeasurement;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A measurement nobody can read is not a measurement. These assertions are deliberately about
 * whether each measured number REACHES the output — the failure this guards against is a column
 * quietly dropped during a refactor, leaving a table that looks complete and is not.
 */
final class BenchmarkRendererTest extends TestCase
{
    public function testEveryMeasuredNumberReachesTheOutput(): void
    {
        $searchMs = new Timings();
        $searchMs->add(12.0);
        $searchMs->add(34.0);

        $report = new BenchmarkReport(
            shopLabel: 'demodata-10k',
            vocabulary: new VocabularyMeasurement(
                availableFields: 104,
                availableValues: 5_213,
                fields: 29,
                values: 29,
                truncated: true,
                vocabularyChars: 1_476,
                promptChars: 4_635,
            ),
            facetProbe: new FacetProbeMeasurement(liveMs: 812.5, cachedMs: 0.4),
            queries: [new QueryMeasurement('jacket', $searchMs, retrieveHits: 431, narrowedTo: 8)],
            cards: new CardEndpointMeasurement(ids: 12, lookups: 12, totalMs: 96.0),
        );

        $output = new BufferedOutput();
        (new BenchmarkRenderer())->render(new SymfonyStyle(new ArrayInput([]), $output), $report);

        $rendered = $output->fetch();

        foreach (['demodata-10k', '104', '5213', '29', '1476', '4635', '812', 'jacket', '431', '12', '96'] as $expected) {
            self::assertStringContainsString($expected, $rendered, \sprintf('%s missing from output', $expected));
        }
    }

    public function testTruncationIsStatedInWordsRatherThanAsABoolean(): void
    {
        $report = new BenchmarkReport(
            shopLabel: 'demodata-10k',
            vocabulary: new VocabularyMeasurement(
                availableFields: 104,
                availableValues: 5_213,
                fields: 29,
                values: 29,
                truncated: true,
                vocabularyChars: 1_476,
                promptChars: 4_635,
            ),
            facetProbe: new FacetProbeMeasurement(liveMs: 1.0, cachedMs: 1.0),
            queries: [],
            cards: new CardEndpointMeasurement(ids: 0, lookups: 0, totalMs: 0.0),
        );

        $output = new BufferedOutput();
        (new BenchmarkRenderer())->render(new SymfonyStyle(new ArrayInput([]), $output), $report);

        // "1" for true would be indistinguishable from a count in a table of counts.
        self::assertStringContainsString('yes', $output->fetch());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Benchmark/BenchmarkRendererTest.php`
Expected: FAIL — `Class "…\BenchmarkRenderer" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Command/Benchmark/BenchmarkRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns a {@see BenchmarkReport} into tables.
 *
 * Separate from {@see BenchmarkRunner} so the numbers can be asserted without parsing console
 * output, and so the runner's tests never depend on a column's heading. Mirrors
 * {@see \Swag\AssistantStarterKit\Command\ProbeRenderer}.
 *
 * The columns are the ones the spec's *Handoff* table names, in that order, so the output can be
 * pasted into the README as-is.
 */
final class BenchmarkRenderer
{
    public function render(SymfonyStyle $io, BenchmarkReport $report): void
    {
        $io->title(\sprintf('Assistant benchmark — %s', $report->shopLabel));

        $this->vocabulary($io, $report->vocabulary);
        $this->facetProbe($io, $report->facetProbe);
        $this->queries($io, $report->queries);
        $this->cards($io, $report->cards);

        $io->comment('Durations are milliseconds. No number here is a pass or a fail.');
    }

    private function vocabulary(SymfonyStyle $io, VocabularyMeasurement $vocabulary): void
    {
        $io->section('What the model is told');
        $io->table(
            ['available fields', 'available values', 'fields sent', 'values sent', 'truncated', 'vocabulary chars', 'prompt chars'],
            [[
                (string) $vocabulary->availableFields,
                (string) $vocabulary->availableValues,
                (string) $vocabulary->fields,
                (string) $vocabulary->values,
                $vocabulary->truncated ? 'yes' : 'no',
                (string) $vocabulary->vocabularyChars,
                (string) $vocabulary->promptChars,
            ]],
        );
    }

    private function facetProbe(SymfonyStyle $io, FacetProbeMeasurement $probe): void
    {
        $io->section('Facet probe');
        $io->table(
            ['live ms', 'cached ms'],
            [[$this->ms($probe->liveMs), $this->ms($probe->cachedMs)]],
        );
    }

    /** @param list<QueryMeasurement> $queries */
    private function queries(SymfonyStyle $io, array $queries): void
    {
        if ([] === $queries) {
            return;
        }

        $io->section('Search, per query');
        $io->table(
            ['term', 'runs', 'p50 ms', 'p95 ms', 'max ms', 'retrieve hits', 'narrowed to'],
            array_map(static fn(QueryMeasurement $query): array => [
                $query->term,
                (string) $query->searchMs->count(),
                \sprintf('%.1F', $query->searchMs->p50()),
                \sprintf('%.1F', $query->searchMs->p95()),
                \sprintf('%.1F', $query->searchMs->max()),
                (string) $query->retrieveHits,
                (string) $query->narrowedTo,
            ], $queries),
        );
    }

    private function cards(SymfonyStyle $io, CardEndpointMeasurement $cards): void
    {
        $io->section('Cards endpoint');
        $io->table(
            ['ids requested', 'catalogue lookups', 'total ms'],
            [[(string) $cards->ids, (string) $cards->lookups, $this->ms($cards->totalMs)]],
        );
    }

    private function ms(float $milliseconds): string
    {
        return \sprintf('%.1F', $milliseconds);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Benchmark/BenchmarkRendererTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
composer run format
composer run quality
git add src/Command/Benchmark/BenchmarkRenderer.php tests/Command/Benchmark/BenchmarkRendererTest.php
git commit -m "feat(benchmark): render the measured numbers as tables"
```

---

### Task 5: The runner, and the command around it

**Files:**
- Create: `src/Command/Benchmark/BenchmarkRunner.php`
- Create: `src/Command/BenchmarkRequest.php`
- Create: `src/Command/BenchmarkCommand.php`
- Modify: `src/Resources/config/services.xml` (beside the `ProbeCommand` block, currently line 246)
- Test: `tests/Command/Benchmark/BenchmarkRunnerTest.php`, `tests/Command/BenchmarkRequestTest.php`

**Interfaces:**
- Consumes: `Timings`, `CountingGateway`, `BenchmarkReport` and the four measurement values, plus these existing classes — `FacetProbe::probe(CatalogScope): FacetSet`, `CatalogVocabulary::renderWithStats(FacetSet): array{text: string, fieldCount: int, valueCount: int, truncated: bool}`, `SystemPrompt::build(AssistantConfig, string $vocabulary = '', string $viewing = ''): string`, `SalesChannelContextProvider::use(SalesChannelContext, callable): mixed`, and `SystemConfigAssistantConfig::forSalesChannel(string $salesChannelId): AssistantConfig` — **verified**: there is no `AssistantConfig` service in `services.xml`; `Core\Config\SystemConfigAssistantConfig` is the registered class (line 92) and `AssistantConfig` is a plain value it returns.
- Produces:
  - `BenchmarkRunner::__construct(CountingGateway $gateway)`
  - `BenchmarkRunner::run(string $shopLabel, AssistantConfig $config, array $terms, int $repetitions, array $cardIds): BenchmarkReport`

**Why the config is a `run()` argument and not a constructor dependency.** `AssistantConfig` is
per-sales-channel, and the only thing that knows the sales channel is the command. Injecting
`SystemConfigAssistantConfig` into the runner would drag `SystemConfigService` — and therefore the
container — into a class whose whole point is that its test constructs it with three lines and a
fixture. The command resolves the config; the runner receives a value.
  - `BenchmarkRequest::fromInput(InputInterface $input, string $defaultSalesChannel): self` with readonly `$salesChannelId`, `$terms`, `$repetitions`, `$cardIds`, `$shopLabel`
  - `BenchmarkRequest::DEFAULT_TERMS` — the fixed query list

**The query list is fixed and committed, not sampled.** A benchmark whose inputs change between runs
cannot be compared with its previous self, and the spec wants a table that is "re-runnable, quotable in
the README". These eight terms are shaped like the journeys: one narrow, one broad, one plural, one
variant-bearing, one price-shaped, one misspelled, one two-word, one that matches nothing.

- [ ] **Step 1: Write the failing test for the request**

Create `tests/Command/BenchmarkRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\BenchmarkRequest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class BenchmarkRequestTest extends TestCase
{
    private const DEFAULT_CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private static function input(array $options): ArrayInput
    {
        return new ArrayInput($options, new InputDefinition([
            new InputOption('term', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('repetitions', null, InputOption::VALUE_REQUIRED, '', '20'),
            new InputOption('card-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('sales-channel', null, InputOption::VALUE_REQUIRED, '', self::DEFAULT_CHANNEL),
            new InputOption('label', null, InputOption::VALUE_REQUIRED, '', 'shop'),
        ]));
    }

    public function testItFallsBackToTheCommittedQueryList(): void
    {
        $request = BenchmarkRequest::fromInput(self::input([]), self::DEFAULT_CHANNEL);

        // The committed list, so two runs of this command are comparable.
        self::assertSame(BenchmarkRequest::DEFAULT_TERMS, $request->terms);
        self::assertSame(20, $request->repetitions);
        self::assertSame(self::DEFAULT_CHANNEL, $request->salesChannelId);
    }

    public function testExplicitTermsReplaceTheDefaultList(): void
    {
        $request = BenchmarkRequest::fromInput(self::input(['--term' => ['jacket', 'gloves']]), self::DEFAULT_CHANNEL);

        self::assertSame(['jacket', 'gloves'], $request->terms);
    }

    public function testRepetitionsBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BenchmarkRequest::fromInput(self::input(['--repetitions' => '0']), self::DEFAULT_CHANNEL);
    }

    public function testRepetitionsIsCappedSoAMistypedFlagCannotRunForHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BenchmarkRequest::fromInput(self::input(['--repetitions' => '100000']), self::DEFAULT_CHANNEL);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Command/BenchmarkRequestTest.php`
Expected: FAIL — `Class "…\BenchmarkRequest" not found`.

- [ ] **Step 3: Write the request**

Create `src/Command/BenchmarkRequest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses this command's input into a value object, so {@see BenchmarkCommand} holds CLI concerns and
 * nothing else. Mirrors {@see ProbeRequest}.
 */
final class BenchmarkRequest
{
    /**
     * The committed query list.
     *
     * Fixed rather than sampled, because a benchmark whose inputs move cannot be compared with its
     * own previous run — and the spec asks for a table that is re-runnable and quotable. The shapes
     * mirror this project's journeys: narrow, broad, plural, variant-bearing, price-shaped,
     * misspelled, multi-word, and one that should match nothing.
     */
    public const DEFAULT_TERMS = [
        'jacket',
        'shoes',
        'gloves',
        'blue jersey',
        'water bottle',
        'jaket',
        'winter cycling jacket',
        'zzzznotathing',
    ];

    /** How many repetitions one run may ask for. A mistyped flag should not run for an hour. */
    private const MAX_REPETITIONS = 1_000;

    /**
     * @param list<string> $terms
     * @param list<string> $cardIds
     */
    private function __construct(
        public readonly string $salesChannelId,
        public readonly array $terms,
        public readonly int $repetitions,
        public readonly array $cardIds,
        public readonly string $shopLabel,
    ) {}

    /**
     * @throws \InvalidArgumentException when `--repetitions` is outside 1..1000
     */
    public static function fromInput(InputInterface $input, string $defaultSalesChannel): self
    {
        $repetitions = (int) self::string($input, 'repetitions', '20');

        if ($repetitions < 1 || $repetitions > self::MAX_REPETITIONS) {
            throw new \InvalidArgumentException(
                \sprintf('--repetitions must be between 1 and %d, got %d.', self::MAX_REPETITIONS, $repetitions),
            );
        }

        $terms = self::strings($input, 'term');
        $salesChannel = self::string($input, 'sales-channel', $defaultSalesChannel);

        return new self(
            salesChannelId: '' === $salesChannel ? $defaultSalesChannel : $salesChannel,
            terms: [] === $terms ? self::DEFAULT_TERMS : $terms,
            repetitions: $repetitions,
            cardIds: self::strings($input, 'card-id'),
            shopLabel: self::string($input, 'label', 'shop'),
        );
    }

    private static function string(InputInterface $input, string $option, string $fallback): string
    {
        $value = $input->getOption($option);

        return \is_string($value) && '' !== $value ? $value : $fallback;
    }

    /** @return list<string> */
    private static function strings(InputInterface $input, string $option): array
    {
        $value = $input->getOption($option);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $item): string => \is_string($item) ? $item : '', $value),
            static fn(string $item): bool => '' !== $item,
        ));
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Command/BenchmarkRequestTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Write the failing test for the runner**

Create `tests/Command/Benchmark/BenchmarkRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRunner;
use Swag\AssistantStarterKit\Command\Benchmark\CountingGateway;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * The runner is where every number comes from, so this is where the SHAPE of the measurement is
 * pinned down. Deliberately no assertion on any duration: a millisecond figure from a fixture
 * gateway describes this machine's mood, and the spec is explicit that a latency is not a pass.
 *
 * What IS asserted: that every field is populated, that repetitions produce that many samples, that
 * the cards count equals one lookup per id, and that nothing writes.
 */
final class BenchmarkRunnerTest extends TestCase
{
    private static function runner(CountingGateway $gateway): BenchmarkRunner
    {
        return new BenchmarkRunner($gateway);
    }

    private static function gateway(): CountingGateway
    {
        return new CountingGateway(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'),
        );
    }

    public function testItMeasuresEveryQueryTheGivenNumberOfTimes(): void
    {
        $report = self::runner(self::gateway())
            ->run('fixture', new AssistantConfig(), ['Trail Jersey', 'bottle'], repetitions: 4, cardIds: []);

        self::assertCount(2, $report->queries);

        foreach ($report->queries as $query) {
            self::assertSame(4, $query->searchMs->count());
        }
    }

    public function testItReportsBothTheAvailableAndTheSentVocabulary(): void
    {
        $report = self::runner(self::gateway())
            ->run('fixture', new AssistantConfig(), ['bottle'], repetitions: 1, cardIds: []);

        // The small fixture fits, so nothing is cut and the two pairs agree — which is exactly what
        // makes a disagreement on a real shop meaningful.
        self::assertGreaterThan(0, $report->vocabulary->availableFields);
        self::assertSame($report->vocabulary->availableFields, $report->vocabulary->fields);
        self::assertFalse($report->vocabulary->truncated);
        self::assertGreaterThan(0, $report->vocabulary->promptChars);
        self::assertGreaterThan($report->vocabulary->vocabularyChars, $report->vocabulary->promptChars);
    }

    public function testTheCardsRowIsOneLookupPerRequestedId(): void
    {
        $ids = ['fx-001', 'fx-004', 'fx-007'];

        $report = self::runner(self::gateway())
            ->run('fixture', new AssistantConfig(), [], repetitions: 1, cardIds: $ids);

        self::assertSame(3, $report->cards->ids);
        self::assertSame(3, $report->cards->lookups, 'the N+1 is the measurement, not an accident');
    }

    public function testItNeverWritesToTheShop(): void
    {
        $gateway = self::gateway();

        self::runner($gateway)
            ->run('fixture', new AssistantConfig(), ['Trail Jersey'], repetitions: 2, cardIds: ['fx-001']);

        // The guarantee that makes this safe to point at any shop, including one nobody snapshotted.
        self::assertSame(0, $gateway->callsTo('addToCart'));
        self::assertSame(0, $gateway->callsTo('cart'));
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Command/Benchmark/BenchmarkRunnerTest.php`
Expected: FAIL — `Class "…\BenchmarkRunner" not found`.

- [ ] **Step 7: Write the runner**

Create `src/Command/Benchmark/BenchmarkRunner.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Measures what the assistant costs against whatever catalogue the gateway is pointed at.
 *
 * **No model, deliberately.** Every number the spec asks for — facet-probe milliseconds, search
 * milliseconds, the vocabulary block's counts, prompt characters, retrieve hits, the cards endpoint's
 * lookups — is produced by code below the model, so a model call would add its own latency and its
 * own nondeterminism to a measurement about the shop. Leaving it out makes the command free to run,
 * repeatable, and honest about what it measures. The model's own cost is a separate question and the
 * eval suite already spends real money answering it.
 *
 * **Above the gateway seam only.** This class never names a Shopware type, which is what lets its
 * test run against {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway} with no
 * database while production hands it the DAL gateway.
 */
final class BenchmarkRunner
{
    public function __construct(
        private readonly CountingGateway $gateway,
    ) {}

    /**
     * @param list<string> $terms
     * @param list<string> $cardIds
     */
    public function run(
        string $shopLabel,
        AssistantConfig $config,
        array $terms,
        int $repetitions,
        array $cardIds,
    ): BenchmarkReport {
        return new BenchmarkReport(
            shopLabel: $shopLabel,
            vocabulary: $this->vocabulary($config),
            facetProbe: $this->facetProbe(),
            queries: array_map(
                fn(string $term): QueryMeasurement => $this->query($term, $repetitions),
                $terms,
            ),
            cards: $this->cards($cardIds),
        );
    }

    private function vocabulary(AssistantConfig $config): VocabularyMeasurement
    {
        $facets = $this->gateway->facets(new CatalogScope());
        $stats = CatalogVocabulary::renderWithStats($facets);

        // Terms facets with values only — exactly what CatalogVocabulary::termsFields() keeps.
        // Counting range facets here would inflate "available" with fields the block never renders
        // by design (a range facet's min/max are numbers, and putting a number in front of the model
        // is the fabrication surface FactRenderer exists to prevent), making the available-vs-sent
        // gap look worse than it is.
        $available = array_filter(
            $facets->facets,
            static fn(Facet $facet): bool => FacetType::Terms === $facet->type && [] !== $facet->values,
        );

        $availableValues = 0;
        foreach ($available as $facet) {
            $availableValues += \count($facet->values);
        }

        return new VocabularyMeasurement(
            availableFields: \count($available),
            availableValues: $availableValues,
            fields: $stats['fieldCount'],
            values: $stats['valueCount'],
            truncated: $stats['truncated'],
            vocabularyChars: \strlen($stats['text']),
            promptChars: \strlen(SystemPrompt::build($config, $stats['text'])),
        );
    }

    /**
     * Cold then warm, because {@see FacetProbe} caches within its own lifetime and the two numbers
     * describe the first turn of a conversation and every later one.
     */
    private function facetProbe(): FacetProbeMeasurement
    {
        $probe = new FacetProbe($this->gateway, new TraceRecorder());
        $scope = new CatalogScope();

        $live = self::time(static fn(): mixed => $probe->probe($scope));
        $cached = self::time(static fn(): mixed => $probe->probe($scope));

        return new FacetProbeMeasurement(liveMs: $live, cachedMs: $cached);
    }

    private function query(string $term, int $repetitions): QueryMeasurement
    {
        $timings = new Timings();
        $scope = new CatalogScope();
        $hits = 0;

        for ($i = 0; $i < $repetitions; ++$i) {
            $cards = [];
            $timings->add(self::time(function () use ($term, $scope, &$cards): void {
                $cards = $this->gateway->search(new ProductQuery(term: $term, limit: 50), $scope);
            }));
            $hits = \count($cards);
        }

        // `narrowedTo` is what the model would actually be shown: SearchProductsTool's default limit.
        // Reported beside the hit count because Phase A found the two are the same field in the
        // tool's reply, so the shopper is never told the difference.
        return new QueryMeasurement($term, $timings, retrieveHits: $hits, narrowedTo: min($hits, 5));
    }

    /** @param list<string> $ids */
    private function cards(array $ids): CardEndpointMeasurement
    {
        $before = $this->gateway->callsTo('product');
        $scope = new CatalogScope();

        $elapsed = self::time(function () use ($ids, $scope): void {
            foreach ($ids as $id) {
                // Exactly what AssistantCardController does: one lookup per id, no batching.
                $this->gateway->product($id, $scope);
            }
        });

        return new CardEndpointMeasurement(
            ids: \count($ids),
            lookups: $this->gateway->callsTo('product') - $before,
            totalMs: $elapsed,
        );
    }

    private static function time(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return (float) (hrtime(true) - $start) / 1_000_000.0;
    }
}
```

- [ ] **Step 8: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Command/Benchmark/BenchmarkRunnerTest.php`
Expected: PASS (4 tests). If `testItReportsBothTheAvailableAndTheSentVocabulary` fails on
`availableFields === fields`, check whether the small fixture now has enough groups to be truncated —
if so, that is a real change to the fixture and the assertion should become `assertLessThanOrEqual`.

- [ ] **Step 9: Write the command**

Create `src/Command/BenchmarkCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRenderer;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRunner;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports what the assistant costs against the shop's real catalogue.
 *
 * Phase B of the catalogue-scale work: phase A crossed every constant this kit sets, but did it with
 * an in-memory fixture, so it could say nothing about what happens below the gateway. This does —
 * facet-probe and search latency, how much of a real catalogue's vocabulary survives the prompt
 * budget, and how many lookups one cards request costs.
 *
 * **Read-only, and it reports rather than asserts.** A latency is not a pass or a fail, so there is
 * nothing here to exit non-zero about; the output is a table meant to be read, compared with the
 * previous run, and quoted.
 *
 * A console command has no HTTP request, so {@see SalesChannelContextProvider::current()} would
 * throw. The context is built here and supplied through that provider's callback scope — the same
 * pattern {@see ProbeCommand} uses, and the reason a real-catalogue measurement is possible from the
 * CLI at all.
 */
#[AsCommand(
    name: 'swag:assistant:benchmark',
    description: 'Measure assistant retrieval cost against the real catalogue. Read-only.',
)]
final class BenchmarkCommand extends Command
{
    /** The Storefront sales channel of the lab environment; overridable for any other shop. */
    private const DEFAULT_SALES_CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function __construct(
        private readonly BenchmarkRunner $runner,
        private readonly SystemConfigAssistantConfig $configs,
        private readonly SalesChannelContextProvider $contextProvider,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly BenchmarkRenderer $renderer = new BenchmarkRenderer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'term',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Search term to measure, repeatable. Defaults to the committed query list.',
            )
            ->addOption('repetitions', null, InputOption::VALUE_REQUIRED, 'Searches per term.', '20')
            ->addOption(
                'card-id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Product id for the cards-endpoint measurement, repeatable (up to CardIdList::MAX_IDS of 12).',
            )
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Name for this shop in the output.', 'shop')
            ->addOption(
                'sales-channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Sales channel id.',
                self::DEFAULT_SALES_CHANNEL,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $request = BenchmarkRequest::fromInput($input, self::DEFAULT_SALES_CHANNEL);

        $context = $this->contextFactory->create(Uuid::randomHex(), $request->salesChannelId);

        return $this->contextProvider->use($context, function () use ($io, $request): int {
            $this->renderer->render($io, $this->runner->run(
                $request->shopLabel,
                // Per sales channel, because the config is: a merchant can configure the assistant
                // differently per channel, and measuring one channel's prompt with another's config
                // would report a prompt size nobody is ever sent.
                $this->configs->forSalesChannel($request->salesChannelId),
                $request->terms,
                $request->repetitions,
                $request->cardIds,
            ));

            return self::SUCCESS;
        });
    }
}
```

- [ ] **Step 10: Register both services**

In `src/Resources/config/services.xml`, immediately after the `ProbeCommand` block (currently ending
line 252), add:

```xml
        <service id="Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRunner">
            <argument type="service" id="Swag\AssistantStarterKit\Command\Benchmark\CountingGateway"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\Benchmark\CountingGateway">
            <argument type="service" id="Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface"/>
        </service>

        <service id="Swag\AssistantStarterKit\Command\BenchmarkCommand">
            <argument type="service" id="Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRunner"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider"/>
            <argument type="service" id="Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory"/>
            <tag name="console.command"/>
        </service>
```

`SystemConfigAssistantConfig` is already registered at line 92 with `SystemConfigService` as its only
argument, so it needs no new definition — just the reference above.

- [ ] **Step 11: Verify the command is wired**

```bash
cd ../shopping-assistant-test
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console cache:clear'
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console list swag:assistant'
```

Expected: `swag:assistant:benchmark` listed beside `swag:assistant:probe`.

Then run it against the **unseeded** shop, which is a real DAL run and the first proof the wiring works:

```bash
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && php bin/console swag:assistant:benchmark --label=before-seed --repetitions=5'
```

Expected: four tables, no exception. On the small dev catalogue `truncated` should read `no`.

- [ ] **Step 12: Full verification**

Run: `vendor/bin/phpunit --exclude-group eval`
Expected: PASS, and the new tests included.

Run: `composer run quality`
Expected: exit 0. If `too-many-methods` fires on `BenchmarkRunner`, it has 8 — check you did not add a
ninth helper; the fix is a second class, not a longer one.

- [ ] **Step 13: Commit**

```bash
composer run format
git add src/Command/ tests/Command/ src/Resources/config/services.xml
git commit -m "feat(benchmark): a read-only command that measures retrieval against a real catalogue"
```

---

### Task 6: The measurement, and the report

**Files:**
- Create: `docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md`

**Interfaces:**
- Consumes: everything above.
- Produces: the numbers phase A could not produce, and the input to the constant-tuning decision spec decision S7 deferred.

No test. The deliverable is a measurement, and a measurement that asserted its own result would not be
one.

**Every command below runs against `shopping-assistant-test`, never the staging shop** (spec decision
S8).

- [ ] **Step 1: Snapshot the database**

```bash
cd ../shopping-assistant-test
docker exec shopping-assistant-test-database-1 sh -c \
  'mysqldump -uroot -proot --single-transaction --routines --events shopware' \
  > /tmp/shopware-before-demodata.sql
ls -lh /tmp/shopware-before-demodata.sql
```

Expected: a file of non-trivial size. **Do not continue if it is empty or the command errored** — the
restore in Step 6 is the only thing that gives the local shop back, and `framework:demodata` adds
rather than replaces, so there is no undo without this file. If the credentials differ, read them from
`.env` in that directory rather than guessing.

- [ ] **Step 2: Record the before-state, so the after-state means something**

```bash
docker exec shopping-assistant-test-database-1 sh -c \
  'mysql -uroot -proot shopware -e "select count(*) products from product; select count(*) groups_ from property_group; select count(*) options_ from property_group_option;"'
```

Write the three numbers down; they go in the report's first table.

- [ ] **Step 3: Seed**

```bash
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && php bin/console framework:demodata --reset-defaults --products=10000 --properties=100 --categories=50'
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && php bin/console dal:refresh:index'
```

`--reset-defaults` keeps it from also generating thousands of orders, customers and reviews nobody is
measuring. `--properties=100` creates each group with `rand(30-300)` options, so the vocabulary is
comfortably past `MAX_FIELDS` (30) and `FACET_VALUE_LIMIT` (50).

`dal:refresh:index` is not optional: search reads the index, and an unindexed 10,000-product catalogue
would measure an empty result set very quickly and look like good news.

Re-run Step 2's counts and write down the after numbers.

- [ ] **Step 4: Measure**

```bash
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && php bin/console swag:assistant:benchmark --label=demodata-10k --repetitions=20' \
  | tee /tmp/benchmark-demodata.txt
```

Then the cards endpoint at its real maximum — 12 ids, which is `CardIdList::MAX_IDS`. Take twelve ids
from the seeded catalogue:

```bash
docker exec shopping-assistant-test-database-1 sh -c \
  'mysql -uroot -proot shopware -N -e "select lower(hex(id)) from product where parent_id is null limit 12;"' \
  > /tmp/card-ids.txt

docker exec shopping-assistant-test-web-1 bash -lc \
  "cd /var/www/html && php bin/console swag:assistant:benchmark --label=demodata-10k-cards --repetitions=1 $(sed 's/^/--card-id=/' /tmp/card-ids.txt | tr '\n' ' ')" \
  | tee /tmp/benchmark-cards.txt
```

- [ ] **Step 5: Run it twice more and keep all three**

```bash
for run in 2 3; do
  docker exec shopping-assistant-test-web-1 bash -lc \
    'cd /var/www/html && php bin/console swag:assistant:benchmark --label=demodata-10k --repetitions=20' \
    | tee /tmp/benchmark-demodata-$run.txt
done
```

Three runs, because a single p95 off a laptop under docker is one number and no indication of its own
spread. The report states the range across runs, not just one run's figure.

- [ ] **Step 6: Restore**

```bash
docker exec -i shopping-assistant-test-database-1 sh -c \
  'mysql -uroot -proot shopware' < /tmp/shopware-before-demodata.sql
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && php bin/console cache:clear && php bin/console dal:refresh:index'
```

Verify the shop is itself again — this is the step that protects `tests/e2e`:

```bash
docker exec shopping-assistant-test-web-1 bash -lc \
  'cd /var/www/html && php bin/console swag:assistant:probe --search="Trail Jersey"'
```

Expected: the demo product, with the trap variants from `docs/HANDOFF.md` §8 — Blue/M at stock 0 and
74.90. Re-run Step 2's counts and confirm they match the before numbers.

- [ ] **Step 7: Write the report**

Create `docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md`. Every cell holds a measured
value or the words `not measured` — no estimates.

```markdown
# Catalogue Scale — Phase B Numbers

**Date:** <the day it ran>
**Shop:** `shopping-assistant-test`, seeded with `framework:demodata --reset-defaults --products=10000 --properties=100 --categories=50`, restored afterwards
**Command:** `swag:assistant:benchmark --label=demodata-10k --repetitions=20`, three runs
**Spec:** `docs/superpowers/specs/2026-08-25-catalogue-scale-robustness-design.md`
**Phase A:** `docs/superpowers/reports/2026-08-25-catalogue-scale-baseline.md`

No number here is a pass or a fail. This is the half phase A could not measure: everything below the
gateway.

## The shop that was measured

| | Before seeding | After seeding |
|---|---|---|
| Products | | |
| Property groups | | |
| Property options | | |

## What the model is told, on a real catalogue

| | Available | Sent | Truncated | Vocabulary chars | Prompt chars |
|---|---|---|---|---|---|
| fields | | | | | |
| values | | | | | |

Phase A's Finding 1 was that this block used to collapse to zero fields above 30 groups. This is the
first measurement of the fix against a real aggregation rather than a fixture — state plainly whether
it held.

## Latency

| Measurement | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| facet probe, live (ms) | | | |
| facet probe, cached (ms) | | | |

| Term | p50 (ms) | p95 (ms) | max (ms) | retrieve hits |
|---|---|---|---|---|

## The cards endpoint

| ids requested | catalogue lookups | total ms | ms per id |
|---|---|---|---|

`CardIdList::MAX_IDS` is 12 because this endpoint performs one lookup per id. Say what 12 costs.

## Findings

One numbered finding per real result, each with the numbers behind it. A finding that cannot name its
evidence is not a finding.

## What this does not say

Model latency and cost — no model was called, deliberately, so none of these numbers include it. The
eval suite measures that half. Also: one laptop, docker, MariaDB with default tuning, cold-ish caches.
These are shape-of-the-curve numbers, not production capacity planning.

## Recommended next step

Whether any constant should now be tuned, and which — the decision spec decision S7 deferred until
both halves were measured. Or the next roadmap item, with the reason for the order.
```

- [ ] **Step 8: Commit**

```bash
git add docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md
git commit -m "docs: what the assistant costs against a real 10,000-product catalogue"
```

---

## Gate

**The constant-tuning decision comes after this report, not inside it.** Spec decision S7 deferred
tuning until both halves were measured; this is the second half. Tuning is its own plan, with its own
failing test per constant, and it cannot be written before these numbers exist.

**The restore in Task 6 Step 6 is not optional.** The local shop is the reference every storefront
check and `tests/e2e` depend on, and `framework:demodata` adds rather than replaces — so skipping the
restore leaves a shop that still passes some tests while its search ranking and vocabulary block are
permanently someone else's.

## Spec coverage

| Spec item | Where |
|---|---|
| `swag:assistant:benchmark`, read-only console command | Task 5 |
| facet-probe milliseconds, `live` vs `cache` | Task 5, `BenchmarkRunner::facetProbe()`; reported in Task 6 |
| search milliseconds at p50 and p95 across N repetitions | Task 1 (percentiles), Task 5 (`query()`), Task 6 (three runs) |
| vocabulary field count / value count / truncation flag | Task 5, `BenchmarkRunner::vocabulary()` — plus the available-vs-sent pair the spec's column implies |
| prompt character count via `SystemPrompt::build()` | Task 5, `BenchmarkRunner::vocabulary()` |
| `retrieve` hit counts | Task 5, `QueryMeasurement::$retrieveHits` |
| cards-endpoint cost, counting queries not just milliseconds | Task 2 (`CountingGateway`), Task 5 (`cards()`), Task 6 Step 4 at 12 ids |
| `ProbeCommand`'s context pattern rather than a new one | Task 5, `BenchmarkCommand::execute()` |
| numbers in a table, re-runnable, quotable | Task 4 (renderer), Task 6 (report) |
| no assertions on latency | Global Constraints; Task 5's runner test asserts no duration |
| S8 never the staging shop | Task 6 preamble; every command names `shopping-assistant-test` |
| snapshot / seed / measure / restore | Task 6 Steps 1, 3, 4, 6 |
| read A's report first | *What changed since the spec was written*; Task 6's report template references A's Finding 1 |

## Known risks

1. **~~`AssistantConfig` may not be a plain service id.~~ Checked while writing this plan, and it is not.** There is no `AssistantConfig` service; `Core\Config\SystemConfigAssistantConfig` (services.xml line 92) exposes `forSalesChannel(string): AssistantConfig`. The plan above already reflects this: the command resolves the config per sales channel and passes the value into `run()`. Recorded here because the obvious wiring — injecting a config into the runner — is wrong, and would look right until the container failed to compile.
2. **`dal:refresh:index` on 10,000 products takes minutes and can run out of memory** in the default container. If it dies, re-run it; if it keeps dying, seed 5,000 instead and say so in the report. A smaller shop honestly labelled beats a bigger one that was never indexed.
3. **The default sales channel id is the lab environment's.** `01a01b4af6567284ac9eeb3616598ac3` is hardcoded in `ProbeCommand` and copied here. If the seeded shop uses another channel, pass `--sales-channel`; a wrong channel yields empty results that look like fast ones.
4. **p95 off a laptop under docker is noisy.** Three runs and a stated range, per Task 6 Step 5. Do not quote a single p95 as though it were a property of the code.
5. **The cards measurement uses parent product ids from SQL.** If the seeded ids are not valid for the chosen sales channel — not assigned to its category tree — every lookup returns null quickly and the row measures nothing. Sanity-check that `catalogue lookups` equals `ids requested` and that the total is not near zero.
