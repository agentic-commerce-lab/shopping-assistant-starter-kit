# Admin Trace View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the merchant a read-only Administration view of every assistant conversation and its pipeline trace, with per-stage timing and automatic retention.

**Architecture:** Trace data already exists and is persisted; nothing about the pipeline changes. Four additions: `TraceRecorder` stamps each event with a monotonic elapsed offset; the two trace entities expose selected fields over the **admin API only**; a hand-written Administration module reads them through the standard DAL admin API; a daily scheduled task prunes old conversations.

**Tech Stack:** PHP 8.2, Shopware 6.7 (`shopware/core`, `shopware/storefront`, adding `shopware/administration`), PHPUnit, Mago (fmt/lint/analyze), Vue 2-style Shopware admin module (Twig templates + `Shopware.Component`).

**Spec:** `docs/superpowers/specs/2026-08-21-admin-trace-view-design.md`

## Global Constraints

- PHP **8.2+**; every new PHP file starts with `declare(strict_types=1);`
- Shopware **`~6.7.0`** for every `shopware/*` dependency
- `composer run quality` must exit **0** (mago fmt --check, mago lint, mago analyze, file-length gate, jscpd, dependency analyser, composer audit)
- `vendor/bin/phpunit --exclude-group eval` must stay green
- **`transcript` never carries `ApiAware`.** No exception, in any task
- **Fields are API-readable by default.** `Field::__construct()` adds `ApiAware(AdminApiSource::class)` to every field and `setFlags()` re-adds it. Declaring no flag closes nothing; `removeFlag(ApiAware::class)` does. This plan adds **no** `ApiAware` anywhere. If you ever do add one, never use the no-argument form — it also opens `/store-api/`
- **No `duration_ms` column, ever** (spec D19). Only `elapsed_ms`
- The Administration module is **read-only**: no create, update or delete path
- Entity classes stay non-`final` (Shopware instantiates and may decorate them); new service classes are `final`

### Facts already verified against the codebase — do not re-litigate

- `TraceRecorder` is constructed with `new TraceRecorder()` at `src/Core/Agent/AssistantAgentFactory.php:64` and `src/Controller/AssistantController.php:94`. **It is built fresh per turn** and is not a container service, so a constructor argument with a default needs no `services.xml` change and "elapsed since construction" *is* "elapsed since turn start". The spec's §5.2 caution is resolved by this.
- `DalConversationStore` has **no automated test** — the v0 spec cut the Shopware integration harness. `ConversationStoreContractTest` runs against `InMemoryConversationStore` via a `protected function store(): ConversationStore` seam. Keep the double faithful; that is what makes the contract test worth anything.
- `ApiAware::__construct(string ...$protectedSources)` builds an **allow**-list despite the parameter name — and `Field::__construct()` already applies `ApiAware(AdminApiSource::class)` to every field. **Corrected 2026-08-21:** an earlier draft of this plan and its spec both claimed the trace entities declared no `ApiAware` and were therefore closed. They were open, `transcript` included.
- `EntityDefinition::defaultFields()` forces `createdAt`/`updatedAt` `ApiAware` on **both** sources and they cannot be suppressed. Every boundary assertion is scoped to *declared* fields.

---

## File Structure

**Created**
- `src/Migration/Migration1787270400AddTraceEventElapsedMs.php` — adds the one column
- `src/Core/Trace/Retention/TraceRetentionSettings.php` — reads the global retention window
- `src/Core/Trace/Retention/TraceRetentionPruner.php` — the prune logic, unit-testable
- `src/Core/Trace/Retention/PruneConversationsTask.php` — the scheduled task
- `src/Core/Trace/Retention/PruneConversationsTaskHandler.php` — thin handler
- `src/Resources/app/administration/src/main.js` — admin entry point
- `src/Resources/app/administration/src/module/swag-assistant-trace/index.js` — module registration
- `src/Resources/app/administration/src/module/swag-assistant-trace/acl/index.js` — privileges
- `src/Resources/app/administration/src/module/swag-assistant-trace/page/…-list/{index.js,…list.html.twig}`
- `src/Resources/app/administration/src/module/swag-assistant-trace/page/…-detail/{index.js,…detail.html.twig}`
- `src/Resources/app/administration/src/module/swag-assistant-trace/snippet/{en-GB,de-DE}.json`
- `tests/Core/Trace/TraceEventApiExposureTest.php` — the boundary guarantee
- `tests/Core/Trace/Retention/TraceRetentionPrunerTest.php`

**Modified**
- `src/Core/Trace/TraceEvent.php` — `+ elapsedMs`
- `src/Core/Trace/TraceRecorder.php` — injectable clock, stamps elapsed
- `src/Entity/TraceEvent/TraceEventEntity.php` — `+ elapsedMs`, R62 docblock rewritten
- `src/Entity/TraceEvent/TraceEventDefinition.php` — `+ elapsed_ms` field, `+ ApiAware`
- `src/Entity/Conversation/ConversationDefinition.php` — `+ ApiAware` (not on `transcript`)
- `src/Core/Trace/DalConversationStore.php` — persists `elapsedMs`
- `src/Resources/config/config.xml` — `+ traceRetentionDays`
- `src/Resources/config/services.xml` — retention services
- `composer.json` — `+ shopware/administration`, rename `build:storefront` → `build`
- `tests/Core/Trace/InMemoryConversationStore.php`, `tests/Core/Trace/TraceRecorderTest.php`, `tests/Core/Trace/ConversationStoreContractTest.php`
- `ARCHITECTURE.md`, `README.md`

---

### Task 1: Elapsed timing in the recorder

**Files:**
- Modify: `src/Core/Trace/TraceEvent.php`
- Modify: `src/Core/Trace/TraceRecorder.php`
- Test: `tests/Core/Trace/TraceRecorderTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `TraceEvent::$elapsedMs` (public readonly `int`); `new TraceRecorder(?\Closure $clock = null)` where `$clock` returns **nanoseconds** as `int`

- [ ] **Step 1: Write the failing test**

Append to `tests/Core/Trace/TraceRecorderTest.php`:

```php
    public function testStampsEachEventWithMillisecondsSinceConstruction(): void
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $recorder = new TraceRecorder($clock);

        $now = 120_000_000; // +120ms
        $recorder->record('understand', ['intent' => 'discovery']);

        $now = 2_400_000_000; // +2400ms
        $recorder->record('retrieve', ['hits' => 6]);

        $events = $recorder->events();

        self::assertSame(120, $events[0]?->elapsedMs);
        self::assertSame(2400, $events[1]?->elapsedMs);
    }

    public function testElapsedIsRelativeToTheRecorderSoEachTurnRestartsAtZero(): void
    {
        // `TraceRecorder` is built fresh per turn (AssistantAgentFactory, AssistantController),
        // which is what makes "since construction" mean "since turn start". A recorder built at
        // an arbitrary clock value must still report offsets, never absolute time.
        $now = 9_000_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $recorder = new TraceRecorder($clock);
        $now = 9_050_000_000;
        $recorder->record('understand', []);

        self::assertSame(50, $recorder->events()[0]?->elapsedMs);
    }

    public function testElapsedNeverGoesBackwardsWithinATurn(): void
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $recorder = new TraceRecorder($clock);

        foreach ([10, 400, 15_800] as $ms) {
            $now = $ms * 1_000_000;
            $recorder->record('stage', []);
        }

        $elapsed = array_map(static fn(\Swag\AssistantStarterKit\Core\Trace\TraceEvent $e): int => $e->elapsedMs, $recorder->events());

        self::assertSame([10, 400, 15_800], $elapsed);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Core/Trace/TraceRecorderTest.php`
Expected: FAIL — `TraceRecorder::__construct()` takes no arguments, and `TraceEvent::$elapsedMs` is undefined.

- [ ] **Step 3: Add `elapsedMs` to the DTO**

Replace the body of `src/Core/Trace/TraceEvent.php`:

```php
final readonly class TraceEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param int                  $elapsedMs milliseconds from the start of the turn to when this
     *                                        event was recorded — a point on a timeline, never a
     *                                        span. See TraceRecorder for why (spec D19).
     */
    public function __construct(
        public int $seq,
        public string $stage,
        public array $payload,
        public int $elapsedMs = 0,
    ) {}
}
```

The default keeps every existing construction site compiling; only `TraceRecorder` and the test double pass it.

- [ ] **Step 4: Add the clock to the recorder**

In `src/Core/Trace/TraceRecorder.php`, add this docblock above the class and replace the property block and `record()`:

```php
/**
 * Collects one turn's pipeline events.
 *
 * **Timing is an offset, never a duration (spec D19).** `record()` is an *entry* marker at some
 * call sites (`BoundedToolbox::execute()` records before the tool runs) and a *completion* marker
 * at others (`SearchProductsTool` records after `gateway->search()` returns). A `durationMs`
 * computed as the gap to the next event would therefore mean a different thing per stage — model
 * latency in one row, tool execution in the next — with no way for a reader to tell which. Ruling
 * R62 refused such a column because it could only ever be written as 0; the objection was that the
 * recorder had no timing at all, not that timing was unwanted. This adds the honest half: the
 * offset the recorder can actually observe. The view derives gaps and shows them as gaps.
 *
 * Offsets are relative to **construction**, and this object is built fresh per turn
 * (`AssistantAgentFactory`, `AssistantController`), so they are turn-relative. A conversation spans
 * turns and `seq` keeps growing across them, so conversation-relative offsets would be meaningless
 * by turn three.
 */
final class TraceRecorder
{
    /** @var list<TraceEvent> */
    private array $events = [];

    private int $seq = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private readonly int $startedAt;

    /**
     * @param (\Closure(): int)|null $clock monotonic **nanosecond** source. Injected so timing is
     *                                      assertable; `hrtime(true)` is not steerable from a test,
     *                                      and an unasserted timing column is the thing R62 warned
     *                                      about.
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
        $this->startedAt = ($this->clock)();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $stage, array $payload): void
    {
        $this->events[] = new TraceEvent($this->seq, $stage, $payload, $this->elapsedMs());
        $this->seq++;
    }

    private function elapsedMs(): int
    {
        return intdiv(($this->clock)() - $this->startedAt, 1_000_000);
    }
```

Leave `events()`, `payload()` and `stages()` exactly as they are.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Core/Trace/TraceRecorderTest.php`
Expected: PASS, including the three pre-existing tests.

- [ ] **Step 6: Run the full suite and the quality gate**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`
Expected: both exit 0.

- [ ] **Step 7: Commit**

```bash
git add src/Core/Trace/TraceEvent.php src/Core/Trace/TraceRecorder.php tests/Core/Trace/TraceRecorderTest.php
git commit -m "feat(trace): stamp each event with its elapsed offset"
```

---

### Task 2: Persist `elapsed_ms`

**Files:**
- Create: `src/Migration/Migration1787270400AddTraceEventElapsedMs.php`
- Modify: `src/Entity/TraceEvent/TraceEventEntity.php`
- Modify: `src/Entity/TraceEvent/TraceEventDefinition.php`
- Modify: `src/Core/Trace/DalConversationStore.php`
- Modify: `tests/Core/Trace/InMemoryConversationStore.php`
- Test: `tests/Core/Trace/ConversationStoreContractTest.php`

**Interfaces:**
- Consumes: `TraceEvent::$elapsedMs` from Task 1
- Produces: `swag_assistant_trace_event.elapsed_ms` column; `TraceEventEntity::getElapsedMs(): int`

- [ ] **Step 1: Write the failing contract test**

Append to `tests/Core/Trace/ConversationStoreContractTest.php`:

```php
    public function testElapsedOffsetsSurviveStorageSoTheAdminCanShowATimeline(): void
    {
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $now = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $trace = new TraceRecorder($clock);
        $now = 120_000_000;
        $trace->record('understand', ['intent' => 'discovery']);
        $now = 2_400_000_000;
        $trace->record('retrieve', ['hits' => 6]);

        $store->append(
            $token,
            new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'Found it.'),
            $trace,
        );

        $stored = $store->traceEvents($token);

        // A store that drops this silently would leave the Administration timeline reading
        // "+0ms" on every row, which looks like data rather than an absent column.
        self::assertSame(120, $stored[0]?->elapsedMs);
        self::assertSame(2400, $stored[1]?->elapsedMs);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Trace/ConversationStoreContractTest.php`
Expected: FAIL — `InMemoryConversationStore` rebuilds each event without `elapsedMs`, so both assertions report `0`.

- [ ] **Step 3: Keep the double faithful**

In `tests/Core/Trace/InMemoryConversationStore.php`, in `append()`, replace the loop body:

```php
        foreach ($trace->events() as $event) {
            $this->events[$token][] = new TraceEvent(
                $offset + $event->seq,
                $event->stage,
                $event->payload,
                $event->elapsedMs,
            );
        }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Trace/ConversationStoreContractTest.php`
Expected: PASS.

- [ ] **Step 5: Add the entity property**

In `src/Entity/TraceEvent/TraceEventEntity.php`, replace the class docblock and add the property after `$stage`:

```php
/**
 * One pipeline stage of one turn.
 *
 * `elapsedMs` is milliseconds from turn start to when the event was recorded — an offset, not a
 * span. Ruling R62 refused a `durationMs` column on the grounds that `TraceRecorder` carried no
 * timing and the column could only ever be written as 0. That precondition is fixed rather than the
 * ruling overturned: the recorder now observes offsets, and offsets are all it can honestly observe
 * (see `TraceRecorder`'s docblock for why a gap-to-next duration would mean a different thing per
 * stage).
 */
```

```php
    protected int $elapsedMs = 0;
```

and the accessors, next to the other getter/setter pairs:

```php
    public function getElapsedMs(): int
    {
        return $this->elapsedMs;
    }

    public function setElapsedMs(int $elapsedMs): void
    {
        $this->elapsedMs = $elapsedMs;
    }
```

- [ ] **Step 6: Declare the field**

In `src/Entity/TraceEvent/TraceEventDefinition.php`, add to `defineFields()` immediately after the `payload` line:

```php
            new IntField('elapsed_ms', 'elapsedMs'),
```

- [ ] **Step 7: Add the migration**

Create `src/Migration/Migration1787270400AddTraceEventElapsedMs.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `swag_assistant_trace_event.elapsed_ms`.
 *
 * A second migration rather than an edit to `Migration1755720000`: the tables exist in installed
 * shops, and a migration that has already run is never re-run.
 *
 * `NOT NULL DEFAULT 0` so rows written before this column existed read as 0 rather than NULL. The
 * Administration renders a 0 offset on a pre-existing row as "—", never as "0ms", because a row
 * that predates the column has no offset rather than an offset of zero.
 */
class Migration1787270400AddTraceEventElapsedMs extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787270400;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, for the same reason as
     *         `Migration1755720000`: a schema change that cannot apply must fail loudly rather than
     *         leave a plugin that boots and then errors when the first trace is written.
     */
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn(
            'SHOW COLUMNS FROM `swag_assistant_trace_event` LIKE :column',
            ['column' => 'elapsed_ms'],
        );

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `swag_assistant_trace_event` ADD `elapsed_ms` INT(11) NOT NULL DEFAULT 0 AFTER `payload`',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the column is additive and carries no data anyone can lose.
    }
}
```

- [ ] **Step 8: Persist it in the DAL store**

In `src/Core/Trace/DalConversationStore.php`, in `append()`, add one line to the event array:

```php
                'payload' => $event->payload,
                'elapsedMs' => $event->elapsedMs,
```

- [ ] **Step 9: Verify**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`
Expected: both exit 0.

Then, against a shop with the plugin installed: `bin/console dal:validate`
Expected: no error for `swag_assistant_trace_event`. This is the only check that the definition and the table agree — the DAL store has no automated test (see Global Constraints).

- [ ] **Step 10: Commit**

```bash
git add src/Migration src/Entity/TraceEvent src/Core/Trace/DalConversationStore.php tests/Core/Trace
git commit -m "feat(trace): persist the elapsed offset per trace event"
```

---

### Task 3: Close the transcript, and lock the boundary  — ✅ DONE (commit `761d528`)

**Files:**
- Modify: `src/Entity/Conversation/ConversationDefinition.php`
- Test: `tests/Core/Trace/TraceEventApiExposureTest.php` (create)

**Interfaces:**
- Consumes: the `elapsedMs` field from Task 2
- Produces: `transcript` closed to every API; everything else admin-readable by framework default

> **This task was originally written backwards.** It specified *adding* `ApiAware(AdminApiSource::class)`
> to eleven fields, on the premise that the definitions declared no flag and were therefore closed.
> `Field::__construct()` adds that exact flag to every field already. The entities were open the
> whole time — `transcript` included, despite `ConversationDefinition`'s docblock asserting the
> opposite. The real work is the inverse: strip the flag from `transcript`. Nothing was ever
> readable over `/store-api/`.

- [x] **Step 1: Write the boundary test**

`tests/Core/Trace/TraceEventApiExposureTest.php`, asserting three things:
1. `transcript` carries no `ApiAware` (it is closed by an explicit `removeFlag`)
2. every field the Administration needs is admin-readable and **not** store-API readable
3. the framework default itself — a freshly constructed `StringField` is `ApiAware(AdminApiSource)` —
   so that if a future Shopware release closes fields by default, this fails and tells the reader
   that `removeFlag` has become redundant instead of letting them discover it

- [x] **Step 2: Run it**

The transcript case fails; the other eleven pass **on the framework default**, which is what
surfaced the error in the original plan.

- [x] **Step 3: Close the transcript**

In `src/Entity/Conversation/ConversationDefinition.php`, import
`Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware` and replace the transcript field:

```php
            // Closed deliberately — see the class docblock. Without this call the field is
            // admin-API readable, because every Field is ApiAware(AdminApiSource) by default.
            (new JsonField('transcript', 'transcript'))->removeFlag(ApiAware::class),
```

Rewrite the class docblock paragraph that claimed no field carries `ApiAware`: state that fields are
open by default, that `removeFlag` is load-bearing, and that removing the flag does not affect the
widget because `read_protected` is an API-layer control and `DalConversationStore::history()` reads
through the DAL.

- [x] **Step 4: Verify**

`vendor/bin/phpunit --exclude-group eval` → 395 tests, 1010 assertions, OK. `composer run quality` → 0.

- [x] **Step 5: Commit** — done as `761d528`.

---

## Deviations found during execution

Recorded as they were hit, so the plan matches what was actually done.

| # | Plan said | Reality |
|---|---|---|
| 1 | `git add … composer.lock` in several commit steps | **`composer.lock` is gitignored in this repo.** Drop it from every commit step; run `composer update --lock` to keep it consistent locally |
| 2 | Task 3 adds `ApiAware` to eleven fields | Backwards — fields are admin-readable by default. See the corrected Task 3 |
| 3 | Task 4's `IdSearchResult` stub passes a list | Its `$data` is `array<string, array{primaryKey, data}>`, **keyed by primary key**. Fixed with a `searchRows()` helper |
| 4 | Task 4's handler takes a bare `EntityRepository` | `mago analyze` requires the generic: `@param EntityRepository<ScheduledTaskCollection>` |
| 5 | Task 4 declares no new composer deps | `PruneConversationsTaskHandler` uses `psr/log` and `symfony/messenger`; `quality:depcheck` failed on both as **shadow dependencies**. Added `psr/log: ^3.0` and `symfony/messenger: ~7.4.0` |
| 6 | Task 5 adds `shopware/administration` | **Not required — do not add it.** `shopware-cli extension build` compiles the admin bundle from `src/Resources/app/administration/src/main.js` alone. The build log never mentions the administration build, so "storefront only" cannot be read out of it; check `src/Resources/public/administration/assets/*.js` instead. Adding the dependency also fails `quality:depcheck` as an UNUSED_DEPENDENCY. See ruling R94 |
| 7 | Task 5 puts the outcome filter in the `#language-switch` slot | That slot is for language switching. The filter now lives in a `sw-card` above the listing |
| 8 | Task 6 splits `payload.js` only if the file-length gate complains | Split up front — it is the part worth unit-testing if a JS harness is ever added |
| 9 | Task 1's `intdiv()` and bare `hrtime(true)` | `mago analyze` rejected both: `hrtime(true)` is typed `int|float|false`, and `intdiv` declares `ArithmeticError`/`DivisionByZeroError`. Cast the clock, divide with `(int) ($ns / 1_000_000)` |

**Verification gap carried forward:** `bin/console dal:validate` (Task 2) and `scheduled-task:list` (Task 4) were not run — they need a live shop. Tasks 5 and 6 have no automated test by design (D21); their acceptance steps are manual and outstanding.

---

### Task 4: Retention

**Files:**
- Create: `src/Core/Trace/Retention/TraceRetentionSettings.php`
- Create: `src/Core/Trace/Retention/TraceRetentionPruner.php`
- Create: `src/Core/Trace/Retention/PruneConversationsTask.php`
- Create: `src/Core/Trace/Retention/PruneConversationsTaskHandler.php`
- Modify: `src/Resources/config/config.xml`, `src/Resources/config/services.xml`
- Test: `tests/Core/Trace/Retention/TraceRetentionPrunerTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces: `TraceRetentionPruner::prune(\DateTimeImmutable $now): int` returning the number of conversations deleted

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Trace/Retention/TraceRetentionPrunerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Retention;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner;
use Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings;

final class TraceRetentionPrunerTest extends TestCase
{
    private const NOW = '2026-08-21 12:00:00';

    public function testDeletesConversationsOlderThanTheWindow(): void
    {
        $ids = [Uuid::randomHex(), Uuid::randomHex()];
        $repository = $this->repositoryReturning($ids);

        $pruner = new TraceRetentionPruner($repository, $this->settings(30), 50);
        $deleted = $pruner->prune(new \DateTimeImmutable(self::NOW));

        self::assertSame(2, $deleted);
    }

    public function testFiltersOnCreatedAtAtTheWindowBoundary(): void
    {
        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            function (Criteria $criteria) use (&$captured): IdSearchResult {
                $captured = $criteria;

                return new IdSearchResult(0, [], $criteria, Context::createDefaultContext());
            },
        );

        $pruner = new TraceRetentionPruner($repository, $this->settings(30), 50);
        $pruner->prune(new \DateTimeImmutable(self::NOW));

        self::assertNotNull($captured);

        $filters = $captured->getFilters();
        self::assertCount(1, $filters);

        $filter = $filters[0];
        self::assertInstanceOf(RangeFilter::class, $filter);
        // Pruning keys on `createdAt` because the migration's index on it exists for no other
        // purpose. 30 days before 2026-08-21 12:00 is 2026-07-22.
        self::assertSame('createdAt', $filter->getField());
        self::assertStringStartsWith('2026-07-22', (string) $filter->getParameter(RangeFilter::LT));
    }

    public function testDeletesNothingWhenNothingIsOldEnough(): void
    {
        $repository = $this->repositoryReturning([]);

        $pruner = new TraceRetentionPruner($repository, $this->settings(30), 50);

        self::assertSame(0, $pruner->prune(new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @param list<string> $ids
     */
    private function repositoryReturning(array $ids): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $calls = 0;

        $repository->method('searchIds')->willReturnCallback(
            function (Criteria $criteria) use ($ids, &$calls): IdSearchResult {
                $calls++;

                // First pass returns the batch, second returns nothing: the pruner loops until a
                // batch comes back empty, so a stub that always answers would spin forever.
                return new IdSearchResult(
                    $calls === 1 ? \count($ids) : 0,
                    $calls === 1 ? array_map(static fn(string $id): array => ['primaryKey' => $id, 'data' => []], $ids) : [],
                    $criteria,
                    Context::createDefaultContext(),
                );
            },
        );

        return $repository;
    }

    private function settings(int $days): TraceRetentionSettings
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn($days);

        return new TraceRetentionSettings($systemConfig);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Core/Trace/Retention/TraceRetentionPrunerTest.php`
Expected: FAIL — neither class exists.

- [ ] **Step 3: Write the settings reader**

Create `src/Core/Trace/Retention/TraceRetentionSettings.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * How long a conversation is kept, in days.
 *
 * **Global, not per sales channel** (spec D20). A scheduled task has no sales-channel context and
 * would otherwise iterate every channel to prune one table.
 *
 * There is no "keep forever" value. An unset or nonsensical setting falls back to
 * {@see self::DEFAULT_DAYS} rather than disabling the task, because a merchant who never opens the
 * retention card must still get pruning — this table accumulates what shoppers typed.
 *
 * `final`: the pruner's unit test mocks `SystemConfigService` rather than subclassing this, so
 * there is nothing to keep open and no interface worth extracting for a single integer.
 */
final readonly class TraceRetentionSettings
{
    public const DEFAULT_DAYS = 30;

    private const KEY = 'SwagAssistantStarterKit.config.traceRetentionDays';

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    public function retentionDays(): int
    {
        $configured = $this->systemConfig->getInt(self::KEY);

        return $configured > 0 ? $configured : self::DEFAULT_DAYS;
    }
}
```

- [ ] **Step 4: Write the pruner**

Create `src/Core/Trace/Retention/TraceRetentionPruner.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * Deletes conversations past the retention window; `ON DELETE CASCADE` takes their trace events.
 *
 * Separated from the scheduled-task handler so it can be unit-tested — the handler is a thin shell,
 * which is how Shopware's own cleanup tasks are shaped (`CleanupWebhookEventLogTaskHandler`
 * delegates to `WebhookCleanup`).
 *
 * **Batched.** One run must never hold a long transaction on a shop serving shoppers, and the first
 * prune after this ships could be large on a shop that has been running the assistant for a while.
 *
 * Keys on `created_at` because the migration's index on it exists for no other purpose.
 */
final readonly class TraceRetentionPruner
{
    public function __construct(
        private EntityRepository $conversationRepository,
        private TraceRetentionSettings $settings,
        private int $batchSize = 100,
    ) {}

    /**
     * @return int the number of conversations deleted
     */
    public function prune(\DateTimeImmutable $now): int
    {
        $cutoff = $now->sub(new \DateInterval('P' . $this->settings->retentionDays() . 'D'));
        $context = Context::createDefaultContext();
        $deleted = 0;

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit($this->batchSize);
            $criteria->addFilter(new RangeFilter('createdAt', [
                RangeFilter::LT => $cutoff->format(\DATE_ATOM),
            ]));

            $ids = $this->conversationRepository->searchIds($criteria, $context)->getIds();

            if ($ids === []) {
                return $deleted;
            }

            $this->conversationRepository->delete(
                array_map(static fn(mixed $id): array => ['id' => $id], $ids),
                $context,
            );

            $deleted += \count($ids);
        }
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Core/Trace/Retention/TraceRetentionPrunerTest.php`
Expected: PASS.

- [ ] **Step 6: Add the task and handler**

Create `src/Core/Trace/Retention/PruneConversationsTask.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily. `ARCHITECTURE.md` calls retention "Not optional" — traces live in the merchant's database
 * and hold what shoppers typed.
 */
class PruneConversationsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'swag_assistant.prune_conversations';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
```

Create `src/Core/Trace/Retention/PruneConversationsTaskHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin shell around {@see TraceRetentionPruner}; the logic is there because it is testable there.
 */
#[AsMessageHandler(handles: PruneConversationsTask::class)]
final class PruneConversationsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly TraceRetentionPruner $pruner,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->pruner->prune(new \DateTimeImmutable());
    }
}
```

- [ ] **Step 7: Wire the services**

In `src/Resources/config/services.xml`, add before `</services>`:

```xml
        <!-- Retention. Not optional: this table accumulates what shoppers typed, and the
             Administration view makes it prominent. -->
        <service id="Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
        </service>

        <service id="Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner">
            <argument type="service" id="swag_assistant_conversation.repository"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings"/>
        </service>

        <service id="Swag\AssistantStarterKit\Core\Trace\Retention\PruneConversationsTask">
            <tag name="shopware.scheduled.task"/>
        </service>

        <service id="Swag\AssistantStarterKit\Core\Trace\Retention\PruneConversationsTaskHandler">
            <argument type="service" id="scheduled_task.repository"/>
            <argument type="service" id="logger"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner"/>
            <tag name="messenger.message_handler"/>
        </service>
```

- [ ] **Step 8: Add the config field**

In `src/Resources/config/config.xml`, add a card before `</config>`:

```xml
    <card>
        <title>Data retention</title>

        <input-field type="int">
            <name>traceRetentionDays</name>
            <label>Keep conversations for (days)</label>
            <defaultValue>30</defaultValue>
            <helpText>Conversations older than this are deleted daily, together with their traces.
                There is no "keep forever": an empty or zero value falls back to 30 days. These rows
                hold what shoppers typed, so an unbounded table is a data-protection problem rather
                than a convenience.</helpText>
        </input-field>
    </card>
```

- [ ] **Step 9: Verify and commit**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`

Against a real shop: `bin/console scheduled-task:list`
Expected: `swag_assistant.prune_conversations` is listed.

```bash
git add src/Core/Trace/Retention src/Resources/config tests/Core/Trace/Retention
git commit -m "feat(trace): prune conversations past the retention window"
```

---

### Task 5: Administration module — dependency, scaffold, listing

**Files:**
- Modify: `composer.json`
- Create: `src/Resources/app/administration/src/main.js`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/index.js`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/acl/index.js`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/snippet/en-GB.json`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/snippet/de-DE.json`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-list/index.js`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-list/swag-assistant-trace-list.html.twig`

**Interfaces:**
- Consumes: the admin-API exposure from Task 3
- Produces: route `swag.assistant.trace.index`; ACL role `swag_assistant_conversation.viewer`

> **No automated test exists for this task or Task 6.** Spec D21: `package.json` is explicitly dev-only and outside CI, and this plan does not add Jest/Vitest. Verification is a successful build plus a manual check. Do not write a test that only asserts a file exists — it would be worse than none.

- [x] **Step 1: Rename the build script only — add no dependency**

In `composer.json`, rename the script, since it builds administration too:

```json
    "build": "shopware-cli extension build ."
```

**Do not add `shopware/administration`.** It is not needed (ruling R94) and fails `quality:depcheck`
as an unused dependency. The plugin's PHP never imports from it.

- [x] **Step 2: Confirm the build produces an admin bundle**

Run: `composer run build`

**Verify by artefact, never by log.** The log prints only `Building storefront assets ended…` and
says nothing about the administration build in either case, which is exactly what caused ruling R94:

```bash
ls src/Resources/public/administration/assets/*.js
grep -o "swag-assistant-trace-list" src/Resources/public/administration/assets/*.js
```

- [ ] **Step 3: Write the entry point and privileges**

`src/Resources/app/administration/src/main.js`:

```js
import './module/swag-assistant-trace';
```

`src/Resources/app/administration/src/module/swag-assistant-trace/acl/index.js`:

```js
/**
 * Read-only by construction: there is no editor or creator role, because the Administration view
 * has no write path and the trace is a record of what happened, not a document.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: null,
    key: 'swag_assistant_conversation',
    roles: {
        viewer: {
            privileges: [
                'swag_assistant_conversation:read',
                'swag_assistant_trace_event:read',
            ],
            dependencies: [],
        },
    },
});
```

- [ ] **Step 4: Register the module**

`src/Resources/app/administration/src/module/swag-assistant-trace/index.js`:

```js
import './acl';
import './page/swag-assistant-trace-list';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('swag-assistant-trace', {
    type: 'plugin',
    name: 'swag-assistant-trace',
    title: 'swag-assistant-trace.general.mainMenuItemGeneral',
    description: 'swag-assistant-trace.general.description',
    color: '#57D9A3',
    icon: 'regular-comments',
    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },
    routes: {
        index: {
            component: 'swag-assistant-trace-list',
            path: 'index',
            meta: { privilege: 'swag_assistant_conversation.viewer' },
        },
    },
    navigation: [{
        label: 'swag-assistant-trace.general.mainMenuItemGeneral',
        color: '#57D9A3',
        path: 'swag.assistant.trace.index',
        icon: 'regular-comments',
        parent: 'sw-settings',
        position: 100,
    }],
});
```

- [ ] **Step 5: Add the snippets**

`snippet/en-GB.json`:

```json
{
    "swag-assistant-trace": {
        "general": {
            "mainMenuItemGeneral": "Assistant conversations",
            "description": "What the shopping assistant understood, retrieved, rendered and refused"
        },
        "list": {
            "columnCreatedAt": "Started",
            "columnSalesChannel": "Sales channel",
            "columnTurnCount": "Turns",
            "columnOutcome": "Outcome",
            "columnTotalMs": "Duration",
            "filterOutcome": "Filter by outcome",
            "emptyState": "No conversations yet."
        },
        "detail": {
            "title": "Conversation",
            "stage": "Stage",
            "elapsed": "At",
            "payload": "Payload",
            "noOffset": "—"
        }
    }
}
```

`snippet/de-DE.json` — same structure, German values:

```json
{
    "swag-assistant-trace": {
        "general": {
            "mainMenuItemGeneral": "Assistent-Konversationen",
            "description": "Was der Einkaufsassistent verstanden, gefunden, ausgegeben und abgelehnt hat"
        },
        "list": {
            "columnCreatedAt": "Gestartet",
            "columnSalesChannel": "Verkaufskanal",
            "columnTurnCount": "Züge",
            "columnOutcome": "Ergebnis",
            "columnTotalMs": "Dauer",
            "filterOutcome": "Nach Ergebnis filtern",
            "emptyState": "Noch keine Konversationen."
        },
        "detail": {
            "title": "Konversation",
            "stage": "Schritt",
            "elapsed": "Bei",
            "payload": "Daten",
            "noOffset": "—"
        }
    }
}
```

- [ ] **Step 6: Write the listing page**

`page/swag-assistant-trace-list/index.js`:

```js
import template from './swag-assistant-trace-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('swag-assistant-trace-list', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            conversations: null,
            isLoading: true,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            outcomeFilter: null,
            outcomeOptions: [
                { value: 'product_shown', label: 'product_shown' },
                { value: 'tool_limit_exceeded', label: 'tool_limit_exceeded' },
                { value: 'escalate', label: 'escalate' },
                { value: 'refused', label: 'refused' },
            ],
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('swag_assistant_conversation');
        },

        columns() {
            return [
                { property: 'createdAt', label: 'swag-assistant-trace.list.columnCreatedAt', primary: true },
                { property: 'salesChannelId', label: 'swag-assistant-trace.list.columnSalesChannel' },
                { property: 'turnCount', label: 'swag-assistant-trace.list.columnTurnCount' },
                // The column that makes this a triage tool rather than a log reader — see the
                // outcome filter below: `tool_limit_exceeded` and `escalate` are the two live
                // failure modes and a merchant needs to find them without reading every row.
                { property: 'outcome', label: 'swag-assistant-trace.list.columnOutcome' },
                { property: 'totalMs', label: 'swag-assistant-trace.list.columnTotalMs' },
            ];
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            if (this.outcomeFilter) {
                criteria.addFilter(Criteria.equals('outcome', this.outcomeFilter));
            }

            this.conversations = await this.repository.search(criteria, Shopware.Context.api);
            this.isLoading = false;
        },

        onOutcomeFilterChange(value) {
            this.outcomeFilter = value;
            this.load();
        },
    },
});
```

`page/swag-assistant-trace-list/swag-assistant-trace-list.html.twig`:

```twig
{% block swag_assistant_trace_list %}
<sw-page class="swag-assistant-trace-list">
    {% block swag_assistant_trace_list_smart_bar_header %}
    <template #smart-bar-header>
        <h2>{{ $tc('swag-assistant-trace.general.mainMenuItemGeneral') }}</h2>
    </template>
    {% endblock %}

    {% block swag_assistant_trace_list_toolbar %}
    <template #language-switch>
        <sw-single-select
            :options="outcomeOptions"
            :value="outcomeFilter"
            :placeholder="$tc('swag-assistant-trace.list.filterOutcome')"
            showClearableButton
            @change="onOutcomeFilterChange"
        />
    </template>
    {% endblock %}

    {% block swag_assistant_trace_list_content %}
    <template #content>
        <sw-entity-listing
            v-if="conversations"
            :items="conversations"
            :repository="repository"
            :columns="columns"
            :isLoading="isLoading"
            :showSelection="false"
            :allowEdit="false"
            :allowDelete="false"
            detailRoute="swag.assistant.trace.detail"
        />
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

- [ ] **Step 7: Build and verify manually**

Run: `composer run build`
Expected: succeeds with no errors.

Then, against a shop with the plugin installed:
1. `bin/console cache:clear`
2. Log in to the Administration, assign the *Assistant conversations viewer* privilege to your role
3. Open **Settings → Assistant conversations**

Expected: the listing renders with one row per conversation, showing started, sales channel, turns, outcome and duration. `detailRoute` will 404 until Task 6 — that is expected here.

- [ ] **Step 8: Run the quality gate**

Run: `composer run quality`
Expected: exit 0. `quality:filesize` walks `src`, so the new JS is in scope; `quality:depcheck` must see `shopware/administration` as used.

- [ ] **Step 9: Commit**

```bash
git add composer.json src/Resources/app/administration   # composer.lock is gitignored here
git commit -m "feat(admin): list assistant conversations in the Administration"
```

---

### Task 6: Administration module — trace detail timeline

**Files:**
- Modify: `src/Resources/app/administration/src/module/swag-assistant-trace/index.js`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/index.js`
- Create: `src/Resources/app/administration/src/module/swag-assistant-trace/page/swag-assistant-trace-detail/swag-assistant-trace-detail.html.twig`

**Interfaces:**
- Consumes: route `swag.assistant.trace.index`, the ACL role, and `elapsedMs` from Tasks 2–5
- Produces: route `swag.assistant.trace.detail`

- [ ] **Step 1: Register the detail route**

In `module/swag-assistant-trace/index.js`, add the import next to the list page:

```js
import './page/swag-assistant-trace-detail';
```

and add to `routes`, after `index`:

```js
        detail: {
            component: 'swag-assistant-trace-detail',
            path: 'detail/:id',
            meta: {
                privilege: 'swag_assistant_conversation.viewer',
                parentPath: 'swag.assistant.trace.index',
            },
        },
```

- [ ] **Step 2: Write the detail page**

`page/swag-assistant-trace-detail/index.js`:

```js
import template from './swag-assistant-trace-detail.html.twig';

const { Criteria } = Shopware.Data;

/**
 * The four fields ARCHITECTURE.md calls "the four ways this class of product lies". They render
 * inline on every row and are never behind an expander — a merchant must not have to go looking
 * for the evidence that the assistant dropped a filter or invented a product.
 */
const ALWAYS_VISIBLE = ['filtersDropped', 'inventedProductIds', 'modelClaimsDiscarded', 'stockSource'];

Shopware.Component.register('swag-assistant-trace-detail', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            conversation: null,
            events: null,
            isLoading: true,
        };
    },

    computed: {
        conversationRepository() {
            return this.repositoryFactory.create('swag_assistant_conversation');
        },

        eventRepository() {
            return this.repositoryFactory.create('swag_assistant_trace_event');
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            this.conversation = await this.conversationRepository.get(
                this.$route.params.id,
                Shopware.Context.api,
            );

            const criteria = new Criteria(1, 500);
            criteria.addFilter(Criteria.equals('conversationId', this.$route.params.id));
            // `seq` is monotonic per conversation (the store offsets each turn), so this is the
            // real execution order across every turn.
            criteria.addSorting(Criteria.sort('seq', 'ASC'));

            this.events = await this.eventRepository.search(criteria, Shopware.Context.api);
            this.isLoading = false;
        },

        /**
         * A row written before `elapsed_ms` existed reads 0, which is absence rather than an offset
         * of zero. Showing "0ms" there would be the always-zero column R62 warned about, one layer up.
         */
        elapsedLabel(event) {
            if (!event.elapsedMs) {
                return this.$tc('swag-assistant-trace.detail.noOffset');
            }

            return `+${event.elapsedMs.toLocaleString()}ms`;
        },

        highlights(event) {
            const payload = event.payload || {};

            return ALWAYS_VISIBLE
                .filter((key) => payload[key] !== undefined)
                .map((key) => ({ key, value: JSON.stringify(payload[key]) }));
        },

        payloadJson(event) {
            return JSON.stringify(event.payload || {}, null, 2);
        },
    },
});
```

`page/swag-assistant-trace-detail/swag-assistant-trace-detail.html.twig`:

```twig
{% block swag_assistant_trace_detail %}
<sw-page class="swag-assistant-trace-detail">
    {% block swag_assistant_trace_detail_header %}
    <template #smart-bar-header>
        <h2>{{ $tc('swag-assistant-trace.detail.title') }}</h2>
    </template>
    {% endblock %}

    {% block swag_assistant_trace_detail_content %}
    <template #content>
        <sw-card-view>
            <sw-loader v-if="isLoading" />

            <sw-card v-if="!isLoading && conversation" :title="$tc('swag-assistant-trace.detail.title')">
                <sw-description-list>
                    <dt>{{ $tc('swag-assistant-trace.list.columnOutcome') }}</dt>
                    <dd>{{ conversation.outcome }}</dd>
                    <dt>{{ $tc('swag-assistant-trace.list.columnTurnCount') }}</dt>
                    <dd>{{ conversation.turnCount }}</dd>
                    <dt>{{ $tc('swag-assistant-trace.list.columnTotalMs') }}</dt>
                    <dd>{{ conversation.totalMs }}ms</dd>
                </sw-description-list>
            </sw-card>

            <sw-card v-if="!isLoading && events" :title="$tc('swag-assistant-trace.detail.stage')">
                <div v-for="event in events" :key="event.id" class="swag-assistant-trace-detail__event">
                    <div class="swag-assistant-trace-detail__row">
                        <span class="swag-assistant-trace-detail__elapsed">{{ elapsedLabel(event) }}</span>
                        <span class="swag-assistant-trace-detail__stage">{{ event.stage }}</span>
                    </div>

                    <sw-description-list v-if="highlights(event).length">
                        <template v-for="item in highlights(event)">
                            <dt :key="item.key + '-k'">{{ item.key }}</dt>
                            <dd :key="item.key + '-v'">{{ item.value }}</dd>
                        </template>
                    </sw-description-list>

                    <sw-collapse :title="$tc('swag-assistant-trace.detail.payload')">
                        <pre>{{ payloadJson(event) }}</pre>
                    </sw-collapse>
                </div>
            </sw-card>
        </sw-card-view>
    </template>
    {% endblock %}
</sw-page>
{% endblock %}
```

- [ ] **Step 3: Build**

Run: `composer run build`
Expected: succeeds.

- [ ] **Step 4: Verify manually — this is acceptance criteria A8 and A10**

Against a shop with the plugin installed and at least one conversation:

1. `bin/console cache:clear`
2. Settings → Assistant conversations → click a row
3. Confirm: every pipeline stage of every turn appears, in order (A8)
4. Confirm: the elapsed column shows increasing offsets within a turn and restarts at the next turn's first stage (A11 in the real world)
5. Confirm: on a turn that dropped a filter or discarded a claim, `filtersDropped` / `inventedProductIds` / `modelClaimsDiscarded` / `stockSource` are visible **without expanding anything** (A10)
6. Confirm with a **non-privileged** admin user that the menu entry is absent

If a run producing those four fields is hard to come by, force one: ask the assistant for a blocked product (`blocklist.filter`), and ask a question about a variant whose parent holds the stock (`stockSource`).

- [ ] **Step 5: Quality gate**

Run: `composer run quality`
Expected: exit 0. If `quality:filesize` fails on the detail page, split `highlights`/`payloadJson` into a sibling `payload.js` helper module rather than raising the limit.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/app/administration
git commit -m "feat(admin): show the trace timeline for a conversation"
```

---

### Task 7: Documentation and rulings

**Files:**
- Modify: `ARCHITECTURE.md`
- Modify: `README.md`
- Modify: `src/Core/Trace/DalConversationStore.php` (docblock only)
- Modify: `src/Migration/Migration1755720000CreateAssistantTables.php` (docblock only)

**Interfaces:**
- Consumes: everything above
- Produces: nothing code-facing

- [ ] **Step 1: Correct the trace-event schema table**

In `ARCHITECTURE.md`, in the `swag_assistant_trace_event` table, replace the `duration_ms` row with:

```
| `elapsed_ms` | int |
```

and add below the table:

> `elapsed_ms` is milliseconds from turn start to when the event was recorded — an offset, not a
> span. `record()` is an entry marker at some call sites and a completion marker at others, so a
> gap-to-next duration would mean a different thing per row. The Administration renders gaps
> visually and claims no durations.

- [ ] **Step 2: Fix the four field names**

Still in `ARCHITECTURE.md`, the four never-collapsed fields are written snake_case but the payload keys are camelCase. Replace:

```
`filters_dropped` · `invented_product_ids` · `model_claims_discarded` · `stock_source`
```

with:

```
`filtersDropped` · `inventedProductIds` · `modelClaimsDiscarded` · `stockSource`
```

- [ ] **Step 3: Replace the generated-admin-ui claim**

In `ARCHITECTURE.md`, replace the component-diagram line `└── generated admin-ui over trace custom entities` with `└── hand-written admin module over the trace entities`, and add a note in the same section:

> **Not generated.** The `admin-ui` XML machinery lives under
> `Core/System/CustomEntity/Xml/Config/AdminUi/` and is applied by `CustomEntityEnrichmentService` —
> it is a **CustomEntity** feature, and custom entities are registered exclusively by `AppManager`
> (ruling R78). D1 chose a plugin, so the generated route does not exist here. This is R78 one layer
> up; do not attempt `admin-ui.xml` again.
>
> A generated view would also have rendered nothing: both definitions declared no `ApiAware` field.
> Spec D18 opens them to `AdminApiSource` only, with `transcript` still closed.

- [ ] **Step 4: Make the retention references true**

In `src/Core/Trace/DalConversationStore.php`, the docblock says *"the retention task exists precisely to remove them"* — it now does; change "exists" to "runs daily and". In `src/Migration/Migration1755720000CreateAssistantTables.php`, change *"A retention task **will** prune conversations"* to *"The retention task prunes conversations"*.

- [ ] **Step 5: Record the two rulings**

Append to `.superpowers/sdd/2026-08-19-shopware-plugin/progress.md`, continuing the numbering from the highest existing ruling:

```markdown
### R92 — Trace entities are admin-API readable; `transcript` is not

The v0 rule was that no declared field carries `ApiAware`, written when nothing consumed the
entities. Its reason — conversation content must not be shopper-reachable — is satisfied by
`new ApiAware(AdminApiSource::class)`, which allows `/api/` and never `/store-api/`. `transcript`
stays closed: it is the verbatim replayable record and the widget's re-hydration source, and the
merchant reads trace events instead.

**Cost if wrong:** the no-argument `new ApiAware()` would put shopper conversations on the store
API. `tests/Core/Trace/TraceEventApiExposureTest.php` fails loudly if anyone writes it.

**Note what this does not buy:** the `understand` payload carries the shopper's message, so an
admin with the ACL can reconstruct most of a conversation. That is the feature, not a leak.

### R93 — `elapsed_ms`, and why R62 stands

R62 refused `durationMs` because `TraceRecorder` carried no timing and the column could only be
written as 0. The precondition is now fixed: the recorder takes an injectable monotonic clock and
stamps each event with its offset from turn start. R62's actual objection — a column nobody can
fill — no longer applies, and its deeper one still holds, which is why this is an **offset** and
not a duration: `record()` is an entry marker in `BoundedToolbox::execute()` and a completion
marker in `SearchProductsTool`, so a gap-to-next duration would mean a different thing per row.

**Cost if wrong:** a merchant reads "retrieve took 15,800ms" when that number is model latency.
```

- [ ] **Step 6: Confirm the README is now true**

`README.md` line ~18 says *"The merchant sees every conversation in the Administration: what the assistant understood, which products it retrieved, which facts it rendered, and what it refused."* Re-read it against the shipped detail page. It should now be accurate — **if any clause is still not true, fix the README rather than leaving it aspirational.**

- [ ] **Step 7: Commit**

```bash
git add ARCHITECTURE.md README.md src/Core/Trace/DalConversationStore.php src/Migration .superpowers
git commit -m "docs: correct the trace schema and the admin-ui claim"
```

---

## Spec coverage

| Spec item | Task |
|---|---|
| §5.1 `transcript` closed via `removeFlag`; everything else default-exposed | 3 |
| §5.1 ACL, read-only | 5 |
| §5.2 injectable clock, elapsed from turn start | 1 |
| §5.2 entity, definition, migration, store persistence | 2 |
| §5.3 dependency, build, listing | 5 |
| §3 Should-have #8 — filterable `outcome` column | 5 |
| §5.3 detail timeline, four never-collapsed fields | 6 |
| §5.4 config field, task, handler, batching, global window | 4 |
| §6 boundary test (A9), timing tests (A11), retention test (A12) | 3, 1, 4 |
| §8 ARCHITECTURE, README, docblocks, rulings | 7 |
| A8, A10 (manual verification) | 6 |
