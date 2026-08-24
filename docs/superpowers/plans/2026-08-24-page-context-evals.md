# Page Context Evals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The eval suite can put a shopper on a page, and it notices when the model stops behaving the way the 2026-08-24 measurement says it does — in either direction: calling a tool it no longer needs, or refusing to call one it does need.

**Architecture:** A journey gains a `page` block carrying the same two ids the storefront sends. `JourneyAttempt` resolves the product id through the gateway and the catalogue scope **exactly as `ShopwareChatTurnRunner` does**, and records the same `page.context` event — a harness that builds the bundle differently from production proves nothing about production. One new assertion, `tool_calls_at_most`, reads what the trace already records. Three journeys use them.

**Tech Stack:** PHP 8.2, PHPUnit, Mago, the existing `src/Eval/` harness. No new dependencies.

**Spec:** None. This plan implements the follow-up named in the `## Measured result` section of `docs/superpowers/plans/2026-08-21-page-context.md`, and every number quoted below comes from that measurement. Read it first — it is the argument for this plan existing.

## Global Constraints

- PHP **8.2+**; every new PHP file starts with `declare(strict_types=1);`
- `composer run quality` must exit **0**; `vendor/bin/phpunit --exclude-group eval` must stay green
- **Evals run against `FixtureCommerceGateway` only** (D10, A7). The suite must keep running with no Shopware, no database and no credentials — `JourneyEvalTest` skips cleanly when the three `ASSISTANT_LLM_*` variables are not all set, and that must stay true
- **No new dependency**, and no new abstraction shared with `src/Core/` — the harness may read production classes, never the other way round
- New parameters and journey keys are **optional with a default**, so the twelve existing journey files keep parsing unchanged

## Design decisions

| # | Decision | Why |
|---|---|---|
| E1 | The journey file gains one **`page`** block with `productId` and `categoryId` — the same two ids, and only ids | It is the same contract the storefront has. A journey that could set anything else would be testing a shop the endpoint cannot produce |
| E2 | **`JourneyAttempt` resolves the product through `gateway->product($id, $config->scope)`** and records `page.context`, mirroring `ShopwareChatTurnRunner` | Fidelity is the whole value of the harness. If the eval hands the factory a card production would have refused, a green journey says nothing about the shop. It also means a journey **can** declare `blockedProductIds` containing its own page product and assert that page context grants nothing — the trust model, checked by an eval rather than only by a unit test |
| E3 | **`JourneyPage` does not enforce the 32-hex shape** that `Controller\PageContext` enforces | Production parses an untrusted HTTP payload; a journey file is committed source, and the fixture's ids are `fx-026-blue-m`. Copying the hex rule here would make every journey unwritable. **Do not "fix" this to match** — the two validate different things for different reasons |
| E4 | The new assertion is **`tool_calls_at_most`**, and `isSafety()` returns **false** | The harness already draws the line this needs: a safety assertion must pass every run, a quality one may pass 2 of 3. A turn that calls `get_product` and renders the right card is **correct**, only slower — so this is not a safety claim, and treating it as one would make normal model variance read as "the assistant is unsafe" |
| E5 | It counts `tool.call` events across the **whole run**, not per turn | That is what `TurnToolCallCounter` already does — a multi-turn journey's last `turn.end` reports a cumulative figure in this harness, because `JourneyAttempt` shares one `TraceRecorder` across turns (ruling R84 documents the same sharing). Counting the events directly makes the semantics visible instead of inheriting them by accident. All three journeys here are single-turn, where the distinction does not arise |
| E6 | Three journeys, and the second one matters most | `page_context_no_lookup` guards a **number**; `page_context_other_variant` guards an **answer**. The 2026-08-24 wording fix pushed the model towards obedience, and the failure it could cause — refusing to search when the shopper asked about a different variant — is a correctness failure, not a latency one |
| E7 | Journey `category` is **`'performance'`** for the two tool-count journeys | `category` is a reporting label; the existing values are `safety`, `grounding`, `action`. A red run in a new, differently-named category should not be read as a grounding regression |

## File Structure

**Created**
- `src/Eval/JourneyPage.php` — validates and carries the `page` block
- `src/Eval/Assertion/ToolCallsAtMost.php` — the assertion
- `tests/Eval/JourneyPageTest.php`
- `tests/Eval/Assertion/ToolCallsAtMostTest.php`
- `tests/Eval/JourneyAttemptPageContextTest.php` — the fidelity check, deterministic, no live model
- `tests/Journeys/page_context_no_lookup.php`
- `tests/Journeys/page_context_other_variant.php`
- `tests/Journeys/page_context_not_a_cage.php`

**Modified**
- `src/Eval/Journey.php` — an eighth field, `page`
- `src/Eval/JourneyFileParser.php` — parses it
- `src/Eval/Assertion/AssertionRegistry.php` — one arm
- `src/Eval/JourneyAttempt.php` — resolves the id and threads both ids into the factory
- `README.md`, `ARCHITECTURE.md` — the journey format, and two stale counts

---

### Task 1: The `tool_calls_at_most` assertion

**Files:**
- Create: `src/Eval/Assertion/ToolCallsAtMost.php`
- Modify: `src/Eval/Assertion/AssertionRegistry.php`
- Test: `tests/Eval/Assertion/ToolCallsAtMostTest.php`

**Interfaces:**
- Consumes: `Assertion`, `AssertionResult`, `TraceEvents::payloads()`, `TraceRecorder`
- Produces: journey assertion name `tool_calls_at_most`, expecting `['limit' => int]`

Nothing in this task depends on Tasks 2–4, and nothing in them depends on this beyond the assertion
name — write it first because it is the piece the whole plan exists to deliver.

- [ ] **Step 1: Write the failing test**

Create `tests/Eval/Assertion/ToolCallsAtMostTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\ToolCallsAtMost;

/**
 * The assertion that guards the 2026-08-24 measurement: with the open product already in its prompt
 * and already registered on the renderer, the model must not spend a round trip fetching it.
 */
final class ToolCallsAtMostTest extends TestCase
{
    public function testATurnThatCalledNoToolMeetsALimitOfZero(): void
    {
        $result = (new ToolCallsAtMost())->evaluate(self::turn(), new TraceRecorder(), ['limit' => 0]);

        self::assertTrue($result->passed, $result->detail);
    }

    public function testOneToolCallBreaksALimitOfZero(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.call', ['stage' => 'dispatch', 'name' => 'get_product']);

        $result = (new ToolCallsAtMost())->evaluate(self::turn(), $trace, ['limit' => 0]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('get_product', $result->detail, 'the detail must name what was called');
    }

    public function testTheLimitIsAnUpperBoundRatherThanAnEquality(): void
    {
        // "At most", not "exactly": a turn that answered without its allowance is not a regression.
        $trace = new TraceRecorder();
        $trace->record('tool.call', ['stage' => 'dispatch', 'name' => 'search_products']);

        $result = (new ToolCallsAtMost())->evaluate(self::turn(), $trace, ['limit' => 2]);

        self::assertTrue($result->passed, $result->detail);
    }

    public function testAMissingOrNonsenseLimitFailsRatherThanPassingVacuously(): void
    {
        // A journey whose expectation nothing reads is a journey that reports green about nothing —
        // the same rule JourneyConfig applies to unknown config keys.
        foreach ([[], ['limit' => 'nought'], ['limit' => -1]] as $expectations) {
            $result = (new ToolCallsAtMost())->evaluate(self::turn(), new TraceRecorder(), $expectations);

            self::assertFalse($result->passed, json_encode($expectations) . ' must not pass');
        }
    }

    public function testItIsNotASafetyAssertion(): void
    {
        // Deliberate, and E4: a turn that calls a tool and renders the right card is correct, only
        // slower. Marking this safety would demand it pass every single run and make ordinary model
        // variance read as an unsafe assistant.
        self::assertFalse((new ToolCallsAtMost())->isSafety());
    }

    private static function turn(): AssistantTurn
    {
        return new AssistantTurn('Here it is.', [], 'product_shown');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Eval/Assertion/ToolCallsAtMostTest.php`
Expected: FAIL — `ToolCallsAtMost` does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Eval/Assertion/ToolCallsAtMost.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * How many tools the model reached for, bounded from above.
 *
 * ## Why this exists
 *
 * Measured 2026-08-24 against the live shop: asked *"is this in stock?"* on the page of a product
 * already named in its prompt and already registered on the `FactRenderer`, the model called
 * `get_product` anyway — two round trips and 13.8 s for a card the shop was going to render either
 * way. One sentence of `ViewingContext` was the cause, and changing it took the same question to
 * one round trip, zero tool calls and 3.7 s.
 *
 * **No unit test in this project can see that.** A tool call the model chose to make is not a bug in
 * any class; the suite stays green through it. The eval suite is the only layer that observes model
 * behaviour, so this is the only place the regression can be caught.
 *
 * ## Why it is not a safety assertion
 *
 * A turn that calls a tool and renders the right card is **correct**, only slower. `isSafety()`
 * returns false so this gets the 2-of-3 threshold quality assertions get; demanding three of three
 * would turn ordinary model variance into a report that the assistant is unsafe, which it would not
 * be.
 *
 * ## What it counts
 *
 * Every `tool.call` event in the run, which is what {@see \Swag\AssistantStarterKit\Core\Agent\TurnToolCallCounter}
 * counts and therefore what `turn.end`'s own `toolCalls` figure means. In this harness one
 * {@see TraceRecorder} spans every turn of a journey (ruling R84), so **the bound is per run, not
 * per turn** — a multi-turn journey must budget for all of its turns together.
 */
final class ToolCallsAtMost implements Assertion
{
    public function name(): string
    {
        return 'tool_calls_at_most';
    }

    /**
     * @param array<string, mixed> $expectations
     */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $limit = $expectations['limit'] ?? null;

        if (!\is_int($limit) || $limit < 0) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "limit" of 0 or more. An expectation nothing reads reports green about nothing.',
            );
        }

        $names = array_map(
            static fn(array $payload): string => \is_string($payload['name'] ?? null) ? $payload['name'] : '?',
            TraceEvents::payloads($trace, 'tool.call'),
        );

        $count = \count($names);

        if ($count <= $limit) {
            return new AssertionResult($this->name(), true, \sprintf('%d tool call(s), limit %d.', $count, $limit));
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('%d tool call(s) against a limit of %d: %s.', $count, $limit, implode(', ', $names)),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Register it**

In `src/Eval/Assertion/AssertionRegistry.php`, add one arm to the `match`, after
`'escalated_with_handoff'`:

```php
            'tool_calls_at_most' => new ToolCallsAtMost(),
```

The `default` arm's throw stays exactly as it is — a typo in a journey file must still fail loudly.

- [ ] **Step 5: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Eval/ && composer run quality`
Expected: PASS, quality exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Eval/Assertion tests/Eval/Assertion/ToolCallsAtMostTest.php
git commit -m "feat(eval): let a journey bound how many tools the model reaches for"
```

---

### Task 2: The `page` block on a journey

**Files:**
- Create: `src/Eval/JourneyPage.php`
- Modify: `src/Eval/Journey.php`
- Modify: `src/Eval/JourneyFileParser.php`
- Test: `tests/Eval/JourneyPageTest.php`

**Interfaces:**
- Consumes: `JourneyField` (for the existing error-message idiom), nothing from Task 1
- Produces: `JourneyPage::parse(mixed $page, string $journeyId): self` with `public ?string $productId` and `public ?string $categoryId`; `Journey::$page` of type `JourneyPage`

- [ ] **Step 1: Write the failing test**

Create `tests/Eval/JourneyPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Eval\JourneyPage;

final class JourneyPageTest extends TestCase
{
    public function testAJourneyWithNoPageBlockIsOnNoPage(): void
    {
        // The twelve journeys written before page context existed must keep parsing unchanged.
        $page = JourneyPage::parse(null, 'some_journey');

        self::assertNull($page->productId);
        self::assertNull($page->categoryId);
    }

    public function testItCarriesTheTwoIdsTheStorefrontSends(): void
    {
        $page = JourneyPage::parse(['productId' => 'fx-026-blue-m', 'categoryId' => 'Jerseys'], 'some_journey');

        self::assertSame('fx-026-blue-m', $page->productId);
        self::assertSame('Jerseys', $page->categoryId);
    }

    public function testEitherIdMayStandAlone(): void
    {
        self::assertSame('Jerseys', JourneyPage::parse(['categoryId' => 'Jerseys'], 'j')->categoryId);
        self::assertNull(JourneyPage::parse(['categoryId' => 'Jerseys'], 'j')->productId);
    }

    /**
     * The rule JourneyConfig already applies to its own block, for the same reason: a key nothing
     * reads makes the journey a claim about a page it was not run on.
     */
    public function testAnUnknownKeyIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/searchTerm/');

        JourneyPage::parse(['searchTerm' => 'jersey'], 'some_journey');
    }

    public function testAnUnusableValueIsRefused(): void
    {
        foreach ([['productId' => ''], ['productId' => 42], ['categoryId' => []], 'not-an-array'] as $bad) {
            try {
                JourneyPage::parse($bad, 'some_journey');
                self::fail(json_encode($bad) . ' must not parse');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('some_journey', $e->getMessage());
            }
        }
    }

    /**
     * E3, and it is deliberate rather than an oversight. `Controller\PageContext` demands 32 hex
     * characters because it parses an untrusted HTTP payload. A journey file is committed source and
     * the eval catalogue is the fixture, whose ids look like `fx-026-blue-m`. Enforcing the hex rule
     * here would make every journey in this suite unwritable.
     */
    public function testFixtureShapedIdsAreAcceptedBecauseTheEvalCatalogueIsTheFixture(): void
    {
        self::assertSame('fx-004-black', JourneyPage::parse(['productId' => 'fx-004-black'], 'j')->productId);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Eval/JourneyPageTest.php`
Expected: FAIL — `JourneyPage` does not exist.

- [ ] **Step 3: Write the implementation**

Create `src/Eval/JourneyPage.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

/**
 * Where the shopper is standing for the duration of a journey: the product they have open, the
 * category they are browsing, or neither.
 *
 * The same two ids the storefront sends, and only those, because that is the whole contract the
 * chat endpoint has. A journey able to declare anything else would be describing a page the
 * storefront cannot produce.
 *
 * **Unknown keys are refused, not ignored** — the rule {@see JourneyConfig} already applies to its
 * own block, for the identical reason: a key nothing reads makes the journey a claim about a page it
 * was not run on, and the cheapest way to make that claim false is a typo.
 *
 * **The 32-hex shape {@see \Swag\AssistantStarterKit\Controller\PageContext} enforces is
 * deliberately not enforced here.** That class parses an untrusted HTTP payload; this one reads
 * committed source, and the catalogue these journeys run against is the fixture, whose ids look like
 * `fx-026-blue-m`. The two validate different things because they guard different risks.
 */
final readonly class JourneyPage
{
    private function __construct(
        public ?string $productId,
        public ?string $categoryId,
    ) {}

    public static function parse(mixed $page, string $journeyId): self
    {
        if ($page === null) {
            return new self(null, null);
        }

        if (!\is_array($page)) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" must declare "page" as an array of ids.',
                $journeyId,
            ));
        }

        $unknown = array_diff(array_keys($page), ['productId', 'categoryId']);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" declares page key(s) %s, which nothing reads. '
                . 'The storefront sends a product id and a category id, and nothing else.',
                $journeyId,
                implode(', ', array_map(static fn(string $key): string => '"' . $key . '"', $unknown)),
            ));
        }

        return new self(
            self::id($page['productId'] ?? null, 'productId', $journeyId),
            self::id($page['categoryId'] ?? null, 'categoryId', $journeyId),
        );
    }

    private static function id(mixed $value, string $key, string $journeyId): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(\sprintf(
                'Journey "%s" has a non-string or empty page "%s".',
                $journeyId,
                $key,
            ));
        }

        return $value;
    }
}
```

- [ ] **Step 4: Carry it on the Journey**

In `src/Eval/Journey.php`, add an eighth constructor property, after `$assertions`:

```php
        /**
         * Where the shopper is standing. Required rather than defaulted: {@see JourneyPage}'s
         * constructor is private, so it cannot be a parameter default — and it does not need to be,
         * because {@see JourneyFileParser} always supplies one and `JourneyPage::parse(null, …)`
         * yields the empty page every journey written before page context existed wants.
         */
        public JourneyPage $page,
```

The class already carries `@mago-expect lint:excessive-parameter-list` whose justification is that
every field is one journey-file concept; extend that comment's list to name the page, so the
suppression still describes what it suppresses.

- [ ] **Step 5: Parse it**

In `src/Eval/JourneyFileParser.php`, add to the returned array, after `'assertions'`:

```php
            'page' => JourneyPage::parse($data['page'] ?? null, $id),
```

and add `page: JourneyPage` to **both** the `@phpstan-type ParsedJourney` line and the `@return`
docblock on `parse()` — they are written out twice in that file and must not drift.

- [ ] **Step 6: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Eval/ && composer run quality`
Expected: PASS. Every existing journey file still parses — none of them declares `page`, and
`parse(null, …)` yields the empty one.

- [ ] **Step 7: Commit**

```bash
git add src/Eval tests/Eval/JourneyPageTest.php
git commit -m "feat(eval): let a journey put the shopper on a page"
```

---

### Task 3: The harness resolves page context the way production does

**Files:**
- Modify: `src/Eval/JourneyAttempt.php`
- Test: `tests/Eval/JourneyAttemptPageContextTest.php`

**Interfaces:**
- Consumes: `Journey::$page` (Task 2), `AssistantAgentFactory::create(..., ?ProductCard $viewing, ?string $browsingCategoryId)`, `CommerceGatewayInterface::product()`
- Produces: a `page.context` trace event in every eval run, with the same payload shape
  `{reported: bool, resolved: ?string, category: ?string}` production records

**This is the task the plan turns on.** A journey is a claim about the shop; if the harness assembles
the bundle differently from `ShopwareChatTurnRunner`, the claim is about a shop nobody ships.

- [ ] **Step 1: Write the failing test**

Create `tests/Eval/JourneyAttemptPageContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyAttempt;
use Swag\AssistantStarterKit\Eval\JourneyPage;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The harness must put the shopper on a page the way the storefront does, or a green journey is a
 * claim about a pipeline nobody ships.
 *
 * Deterministic: the model is a scripted `MockHttpClient` that answers in one plain sentence, so
 * this runs in the default suite with no credentials and no spend.
 */
final class JourneyAttemptPageContextTest extends TestCase
{
    use UsesCatalogFixture;

    public function testTheOpenProductIsResolvedPreGroundedAndTraced(): void
    {
        [, $trace] = $this->attempt()->run($this->journey(JourneyPage::parse(
            ['productId' => 'fx-026-blue-m'],
            'page_context_fidelity',
        )), 'is this in stock?');

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload);
        self::assertTrue($payload['reported']);
        self::assertSame('fx-026-blue-m', $payload['resolved']);
    }

    /**
     * The trust model, checked by the harness rather than only by a unit test: a journey may block
     * the very product its page declares, and page context must grant nothing.
     */
    public function testAProductTheScopeRefusesIsNotResolved(): void
    {
        $journey = $this->journey(
            JourneyPage::parse(['productId' => 'fx-026-blue-m'], 'page_context_fidelity'),
            config: ['blockedProductIds' => ['fx-026-blue-m']],
        );

        [, $trace] = $this->attempt()->run($journey, 'is this in stock?');

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload);
        self::assertTrue($payload['reported'], 'the journey did report a page product');
        self::assertNull($payload['resolved'], 'the catalogue scope must refuse it');
    }

    public function testTheBrowsedCategoryIsCarriedAndTraced(): void
    {
        [, $trace] = $this->attempt()->run($this->journey(JourneyPage::parse(
            ['categoryId' => 'Jerseys'],
            'page_context_fidelity',
        )), 'what do you have?');

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload);
        self::assertFalse($payload['reported']);
        self::assertSame('Jerseys', $payload['category']);
    }

    public function testAJourneyWithNoPageRecordsAnEmptyPageContext(): void
    {
        [, $trace] = $this->attempt()->run($this->journey(JourneyPage::parse(null, 'j')), 'hello');

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload, 'production records this stage on every turn, so the harness must too');
        self::assertFalse($payload['reported']);
        self::assertNull($payload['resolved']);
        self::assertNull($payload['category']);
    }

    /** @param array<string, mixed> $config */
    private function journey(JourneyPage $page, array $config = []): Journey
    {
        return new Journey(
            id: 'page_context_fidelity',
            category: 'grounding',
            runs: 1,
            archetypes: ['expert' => 'placeholder'],
            config: $config,
            turns: ['archetype'],
            assertions: [],
            page: $page,
        );
    }

    private function attempt(): JourneyAttempt
    {
        $http = new MockHttpClient(static fn(): MockResponse => new MockResponse(json_encode(
            ['choices' => [['message' => ['content' => 'Certainly.'], 'finish_reason' => 'stop']]],
            \JSON_THROW_ON_ERROR,
        )));

        return new JourneyAttempt(
            new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
            self::catalogFixturePath(),
            $http,
        );
    }
}
```

`run()` returns `[AssistantTurn, TraceRecorder]`, so `[, $trace]` takes the second. `Journey`'s
constructor is public and takes named arguments — that is how `Journey::fromFile()` calls it.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Eval/JourneyAttemptPageContextTest.php`
Expected: FAIL — no `page.context` event exists, so `payload()` returns null.

- [ ] **Step 3: Resolve and thread, mirroring the runner**

In `src/Eval/JourneyAttempt.php`, replace the single `create()` call:

```php
        // Resolved through the same gateway and the same CatalogScope a real turn uses, because that
        // is what makes a journey a claim about the shipped pipeline: ShopwareChatTurnRunner does
        // exactly this, and a harness that skipped it could pre-ground a card production would have
        // refused. A blocked page product therefore resolves to null here too.
        $viewing = $journey->page->productId === null
            ? null
            : $gateway->product($journey->page->productId, $config->scope);

        $bundle = AssistantAgentFactory::withCoreToolsOnly($this->http)->create(
            $gateway,
            $config,
            true,
            $this->llm,
            viewing: $viewing,
            browsingCategoryId: $journey->page->categoryId,
        );

        // Recorded before any turn runs, as production records it, so a journey can assert on it.
        $bundle->trace->record('page.context', [
            'reported' => $journey->page->productId !== null,
            'resolved' => $viewing?->id,
            'category' => $journey->page->categoryId,
        ]);
```

Add the `use` for nothing new — `AssistantAgentFactory` and `FixtureCommerceGateway` are already
imported, and `$viewing` needs no type import because it is inferred.

Then extend the class docblock with one paragraph, because this is now a second place where the
harness deliberately mirrors production and a reader needs to know it was not accidental:

```php
 * **Page context is resolved here the way `ShopwareChatTurnRunner` resolves it**, through
 * `gateway->product($id, $config->scope)`, and the resulting `page.context` event is recorded
 * before the first turn. A journey may therefore block its own page product and assert that page
 * context granted nothing — the trust model checked by an eval rather than only by a unit test.
```

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Eval/JourneyAttemptPageContextTest.php`
Expected: PASS, all four cases.

- [ ] **Step 5: Verify nothing else moved**

Run: `vendor/bin/phpunit --exclude-group eval && composer run quality`
Expected: green, exit 0. The twelve existing journeys resolve `page` to the empty one, so their
`page.context` payload is all-null and no assertion they declare reads it.

- [ ] **Step 6: Commit**

```bash
git add src/Eval/JourneyAttempt.php tests/Eval/JourneyAttemptPageContextTest.php
git commit -m "feat(eval): put the shopper on a page the way the storefront does"
```

---

### Task 4: The three journeys

**Files:**
- Create: `tests/Journeys/page_context_no_lookup.php`
- Create: `tests/Journeys/page_context_other_variant.php`
- Create: `tests/Journeys/page_context_not_a_cage.php`
- Modify: `README.md`, `ARCHITECTURE.md`

**Interfaces:**
- Consumes: `tool_calls_at_most` (Task 1), the `page` block (Task 2), the harness fidelity (Task 3)
- Produces: three journeys `JourneyEvalTest` picks up by glob

The fixture facts these rest on, read from `tests/Fixtures/catalog.json` — check them before writing,
because every expectation below is derived from them:

| id | options | price | stock | categoryPath |
|---|---|---|---|---|
| `fx-026-blue-m` | Blue / M | 49.90 | **0** | Apparel, Jerseys |
| `fx-026-blue-l` | Blue / L | 49.90 | 12 | Apparel, Jerseys |
| `fx-026-black-m` | Black / M | **54.90** | 3 | Apparel, Jerseys |
| `fx-004-black` | Black | 24.00 | 5 | Apparel, Gloves |

`fx-004-black` is the catalogue's **only** glove, which is what makes the third journey's exact
expectation safe — `plural_finds_singular` already relies on the same fact.

- [ ] **Step 1: Write the first journey**

Create `tests/Journeys/page_context_no_lookup.php`:

```php
<?php

declare(strict_types=1);

// The measurement this journey exists to defend, from docs/superpowers/plans/2026-08-21-page-context.md:
//
//   wording v1 ("… use your tools")      2 round trips   1 tool call    13 838 ms
//   wording v2 ("its card is already …") 1 round trip    0 tool calls    3 656 ms
//
// Same architecture, same code, one sentence of ViewingContext. Nothing in the 584-test suite can
// see the difference: a tool call the model chose to make is not a bug in any class. This is the
// only layer that notices, which is the entire argument for spending model tokens on it.
//
// The shopper is on the sold-out variant, deliberately. `stock_matches_source` then pins the harder
// half: the card must carry the real 0, and it must get there with no tool call — proving the
// pre-grounded batch is what `grounding.select` rendered from.
return [
    'id' => 'page_context_no_lookup',
    'category' => 'performance',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'is this in stock?',
        'beginner' => 'hey is this one available in my size?',
    ],
    'config' => [],
    'page' => ['productId' => 'fx-026-blue-m'],
    'assertions' => [
        'tool_calls_at_most' => ['limit' => 0],
        'rendered_ids_exactly' => ['expect' => ['fx-026-blue-m']],
        'stock_matches_source' => ['scope' => 'variant', 'expect' => ['fx-026-blue-m' => 0]],
        'no_unbacked_price_in_prose' => [],
        'no_invented_product' => [],
    ],
];
```

- [ ] **Step 2: Write the second journey**

Create `tests/Journeys/page_context_other_variant.php`:

```php
<?php

declare(strict_types=1);

// The opposite failure, and the more important one.
//
// `page_context_no_lookup` guards a NUMBER. This guards an ANSWER: the 2026-08-24 wording fix told
// the model not to call a tool for the product on screen, and the way that goes wrong is a model so
// obedient it stops searching when the shopper asked about something else. A shopper on Blue/M
// asking for black must get Black/M — a different variant, at a different price.
//
// 54.90 against the 49.90 the open product costs is the point of picking this pair: a model that
// answered from the page instead of resolving would quote the wrong figure, and
// `price_matches_source` is what catches it.
//
// The limit is 2, not 1: one search plus one variant resolution is a legitimate shape here, and
// this journey is not the place to litigate retrieval strategy.
return [
    'id' => 'page_context_other_variant',
    'category' => 'performance',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do you have this in black, size M?',
        'beginner' => 'is there a black one of these?',
    ],
    'config' => [],
    'page' => ['productId' => 'fx-026-blue-m'],
    'assertions' => [
        'tool_calls_at_most' => ['limit' => 2],
        'rendered_ids_exactly' => ['expect' => ['fx-026-black-m']],
        'price_matches_source' => ['expect' => ['fx-026-black-m' => 54.90]],
        'no_unbacked_price_in_prose' => [],
        'no_invented_product' => [],
    ],
];
```

**Check `price_matches_source`'s expectation shape against `src/Eval/Assertion/PriceMatchesSource.php`
before running** — `variant_price.php` is the journey that already uses it, and its block is the
one to copy if the shape above differs.

- [ ] **Step 3: Write the third journey**

Create `tests/Journeys/page_context_not_a_cage.php`:

```php
<?php

declare(strict_types=1);

// P9, as a journey: a category is a helpful default, not a cage.
//
// The shopper is standing in Jerseys and asks for gloves. `SearchProductsTool` constrains the search
// to the category, finds nothing, and retries without it — recording `retrieve.without_category`.
// If that retry ever stops happening, this journey renders nothing and the shopper is told the shop
// has no gloves, which is the exact failure `no_match_not_absence` was written about.
//
// `fx-004-black` is the catalogue's only glove and its single sellable variant, so the exact
// expectation is safe — `plural_finds_singular` rests on the same fact.
//
// This is a grounding journey, not a performance one: nothing here is about speed. It asserts that
// an answer exists at all.
return [
    'id' => 'page_context_not_a_cage',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => [
        'expert' => 'do you have gloves?',
        'beginner' => 'looking for some gloves for commuting',
    ],
    'config' => [],
    'page' => ['categoryId' => 'Jerseys'],
    'assertions' => [
        'rendered_ids_exactly' => ['expect' => ['fx-004-black']],
        'no_absence_claim_in_prose' => [],
        'no_invented_product' => [],
        'no_unbacked_price_in_prose' => [],
    ],
];
```

- [ ] **Step 4: Verify they parse without spending a token**

The eval suite skips without credentials, so parsing is checked separately:

```bash
vendor/bin/phpunit --exclude-group eval
```

`tests/Eval/JourneyTest.php` covers `Journey::fromFile()`. If it does not already glob every file
under `tests/Journeys/`, add one case there that loads all of them and asserts each parses — a
journey file with a typo must fail in the default suite, not only when someone has an API key:

```php
    public function testEveryCommittedJourneyParses(): void
    {
        $paths = glob(__DIR__ . '/../Journeys/*.php') ?: [];

        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            $journey = Journey::fromFile($path);

            self::assertNotSame('', $journey->id, $path);
        }
    }
```

- [ ] **Step 5: Run the three journeys against a real model**

This spends tokens. Three journeys × two archetypes × three runs = **18 model turns**.

```bash
vendor/bin/phpunit --group eval --filter 'page_context'
```

Record the pass counts per journey and archetype in this file under a `## First eval run` heading —
including reds. `no_match_not_absence.php` is the precedent for how a red journey is documented: the
number is the finding, and a journey that is honestly red is worth more than one tuned until green.

**Expected, from the live measurement:** `page_context_no_lookup` should pass — the same question
took zero tool calls against the real shop. If it is red, the wording regressed or the model changed,
and either is exactly what this journey was built to report.

- [ ] **Step 6: Update the two stale counts**

`README.md`'s eval section says *"Eight journeys"* and `ARCHITECTURE.md` says *"12 fixtures, 6
journeys, 7 assertions"*. Both were already wrong before this plan — there are 12 journeys and 10
assertions today, and 15 and 11 after it. Correct them to the real numbers, and add the `page` block
to whatever README says about the journey file format.

- [ ] **Step 7: Commit**

```bash
git add tests/Journeys tests/Eval README.md ARCHITECTURE.md docs/superpowers/plans/2026-08-24-page-context-evals.md
git commit -m "test(eval): notice when the model stops honouring page context"
```

---

## Spec coverage

| Decision | Task |
|---|---|
| E1 journey declares only the two ids | 2 |
| E2 harness resolves through gateway + scope, records `page.context` | 3 |
| E3 no hex validation in the harness, and the reason is pinned by a test | 2 |
| E4 `tool_calls_at_most`, `isSafety(): false` | 1 |
| E5 the bound is per run, documented | 1 |
| E6 three journeys, the variant one carrying the correctness claim | 4 |
| E7 `performance` category for the two tool-count journeys | 4 |

## Known risks

| Risk | Severity | Mitigation |
|---|---|---|
| `page_context_no_lookup` is flakier than the twelve existing journeys, because it asserts a model's discretion rather than a right-or-wrong outcome | **high** — a noisy journey gets ignored, and an ignored journey guards nothing | `isSafety(): false` gives it the 2-of-3 threshold; `runs: 3`; its own `performance` category; and Step 5 records the real numbers instead of assuming green |
| Someone reads a red `performance` journey as a safety regression | medium | The category, and the assertion's docblock saying in the first paragraph that a tool call is correct-but-slower |
| Someone "fixes" `JourneyPage` to enforce the 32-hex shape `Controller\PageContext` uses | medium — it would make every journey unwritable, and look like a consistency improvement | E3, the docblock, and `testFixtureShapedIdsAreAccepted…` fails immediately |
| The harness drifts from `ShopwareChatTurnRunner` again as page context grows | **high** — it silently turns every journey into a claim about a pipeline nobody ships | Task 3's four cases, one of which asserts the blocklist refusal end to end through the harness |
| 18 model turns per run makes the suite slower and dearer | low | Already true of the suite; README documents `--filter` for one journey, and Step 5 uses it |
