# Extension Seams Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make four of the seven extension points Linear's v0 list names actually exist — tools, system prompt, LLM platform, trace sinks — so an agency can extend this plugin without forking it.

**Architecture:** `AssistantAgentFactory` stops being a static god-factory and becomes a service that the container contributes to. Contributed tools arrive as *factories* rather than services, because ruling R32 requires exactly one gateway, trace and renderer per request threaded into every tool. Tool authority is split into two tiers enforced by two **unrelated** context types, so a third-party tool cannot reach the catalogue unless it says so in its own interface name.

**Tech Stack:** PHP 8.2, Symfony DI (tagged iterators), Symfony AI 0.12, PHPUnit 11.

**Spec:** No separate design document. The design was settled in conversation on 2026-08-23 and is recorded under *Design of record* below; this plan is the design of record.

## Design of record

Four decisions, taken in that conversation, that the tasks below implement.

### 1. Four seams, not seven

| Build now | Why |
|---|---|
| `ToolFactoryInterface` / `GroundedToolFactoryInterface` | The one an agency hits first, and Linear's acceptance signal is about agencies |
| `PromptProviderInterface` | Falls out of the same change; the prompt is the second thing anyone wants to alter |
| `LlmPlatformInterface` | `PlatformFactory::create()` is static and pins the generic OpenAI bridge; Symfony AI ships 35+ others we cannot reach |
| `TraceSinkInterface` | Closes half of Linear's "analytics destinations" *and* its "analytics sinks" extension point |

Deferred, deliberately: **ranking rules** (they run inside the gateway's `search()`, applied together
with the limit — extracting them is its own change), **knowledge sources** (no retrieval architecture
to hang them on), **MCP / WebMCP / UCP** (`VISION.md` non-goals).

Every interface here ships with a real consumer in this repo. That is a hard rule: `ARCHITECTURE.md`
spent months documenting six interfaces that did not exist, and an interface with no caller is a
guess we would then owe stability on.

### 2. Two tiers of tool authority, enforced by types that do not inherit

`VISION.md`'s first non-negotiable is that the model is *structurally* incapable of inventing a price:
facts reach it only through `FactRenderer`. A third-party tool returning `['price' => 19.90]` would
end that, so:

- `ToolContext` — `trace`, `config`. Nothing else. A store locator, an FAQ lookup, a shipping
  calculator. Cannot put a catalogue fact in front of the model because it cannot obtain one.
- `GroundedToolContext` — the gateway, renderer, facet probe, blocklist, variant resolver, query
  builder, and whether a cart exists. A tool that genuinely needs catalogue data implements
  `GroundedToolFactoryInterface`, whose name is the warning.

**The two context classes deliberately do not share a parent.** If `GroundedToolContext extended
ToolContext`, an unprivileged factory typed against `ToolContext` would receive a grounded instance at
runtime and could downcast to it. Two unrelated `final readonly` classes make the boundary structural
rather than advisory — the same argument as the blocklist being a retrieval filter rather than a
prompt instruction.

### 3. The factory becomes a service, with a named constructor for the container-less callers

Three of six call sites have no container: `Eval\JourneyAttempt` and two test classes construct the
factory directly, and that is deliberate — the eval suite's selling point is "no Shopware, no
database, runs in seconds". So `AssistantAgentFactory::withCoreToolsOnly()` builds the shipped four
tools, the default prompt provider and the default platform with no container at all.

`create()` keeps ownership of constructing the per-request trace, renderer and probe, which is what
preserves R32: the same method that guarantees one-gateway-per-request today still does.

`$http` moves off `create()` and into the platform implementation, which takes `create()` from five
parameters to four — under mago's threshold of 5, so no pragma is needed where one would otherwise be.

### 4. Trace sinks run synchronously, after persistence, exception-isolated

Not through Messenger, and the reasoning matters:

- Shopware installs vary in how transports are configured. An async sink that silently never runs is
  worse than a sync one that does, and a starter kit must not require a worker process.
- The trace is **already** persisted synchronously in the shopper's request by `DalConversationStore`,
  so a sink adds no new class of cost.
- Sinks run **after** `append()`, so a failing sink cannot cost the merchant their audit log.
- Every sink is wrapped: a throwing sink is traced and swallowed. The shopper's turn already
  succeeded; a broken analytics integration must not turn a delivered answer into a 500.

The documented cost: a slow sink adds latency to the shopper's request. Said out loud in the docs, not
hidden.

## Global Constraints

- PHP **8.2+**, `declare(strict_types=1)` everywhere, non-disableable.
- `composer run quality` must exit **0**. `excessive-parameter-list` threshold **5**, `too-many-methods` fires past ~15, `cyclomatic-complexity` 10. Split rather than suppress; use `// @mago-expect lint:<rule>` with a justification only where the codebase already does (`ProductCard`, `AssistantConfig`, `AssistantController`).
- `vendor/bin/mago fmt` before every commit. The formatter re-indents concatenations and argument lists — read a file back before matching on its text.
- The deterministic suite (`--exclude-group eval`) must never need a live model. **Never run `vendor/bin/phpunit tests/Eval` without `--exclude-group eval`**: `tests/bootstrap.php` loads `.env` and that command fires paid live turns.
- **R32 is the constraint this whole plan risks.** Exactly one `CommerceGatewayInterface`, one `TraceRecorder` and one `FactRenderer` per request, shared by every tool. `AddToCartTool` reads `gateway->cart()->total` live to enforce `maxCartValue`; a per-tool gateway lets a model drip-feed past the limit one item at a time. Task 2 adds a test that pins this across contributed tools, and that test is the point of Task 2.
- Symfony AI tools have **no common interface** — they are plain classes carrying `#[AsTool]`. Factory return types are therefore `?object`, which is honest rather than lazy: ADR 0001 accepted the framework's attribute as the contract.
- This is a research preview. New interfaces get `@api` to record intent, and the README keeps saying there are no stability guarantees yet.

---

### Task 1: The two contexts and the two factory interfaces

Types only, no wiring, no behaviour change. Ends with a green suite and nothing using them yet — the
next task migrates the shipped tools onto them, which is what proves they fit.

**Files:**
- Create: `src/Core/Tool/Factory/ToolContext.php`
- Create: `src/Core/Tool/Factory/GroundedToolContext.php`
- Create: `src/Core/Tool/Factory/ToolFactoryInterface.php`
- Create: `src/Core/Tool/Factory/GroundedToolFactoryInterface.php`
- Test: `tests/Core/Tool/Factory/ToolAuthorityTest.php`

**Interfaces:**
- Consumes: `TraceRecorder`, `AssistantConfig`, `CommerceGatewayInterface`, `FactRenderer`, `FacetProbe`, `BlocklistFilter`, `VariantResolver`, `QueryBuilder`.
- Produces: `ToolFactoryInterface::create(ToolContext): ?object`, `GroundedToolFactoryInterface::create(GroundedToolContext): ?object`.

- [ ] **Step 1: Write the failing test**

Create `tests/Core/Tool/Factory/ToolAuthorityTest.php`. This tests the *boundary*, which is the
only thing types alone can get wrong:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;

/**
 * The two-tier boundary, asserted structurally rather than trusted.
 *
 * `VISION.md`'s first non-negotiable is that the model is *structurally* incapable of inventing a
 * price. A contributed tool that could reach the gateway could hand the model a raw price and end
 * that, so an unprivileged tool must not be able to obtain one — not by convention, by type.
 */
final class ToolAuthorityTest extends TestCase
{
    public function testTheUnprivilegedContextExposesNothingBeyondTraceAndConfig(): void
    {
        // A property added here later is a hole opened here later. The assertion is deliberately
        // exhaustive so widening the unprivileged context cannot happen by accident.
        $properties = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(ToolContext::class))->getProperties(),
        );

        sort($properties);

        self::assertSame(['config', 'trace'], $properties);
    }

    public function testTheTwoContextsShareNoParent(): void
    {
        // If GroundedToolContext extended ToolContext, a factory typed against the unprivileged one
        // would receive a grounded instance at runtime and could downcast to it. Unrelated classes
        // make the boundary structural instead of advisory.
        self::assertFalse(is_subclass_of(GroundedToolContext::class, ToolContext::class));
        self::assertFalse(is_subclass_of(ToolContext::class, GroundedToolContext::class));
        self::assertSame([], class_parents(ToolContext::class));
        self::assertSame([], class_parents(GroundedToolContext::class));
    }

    public function testBothContextsAreFinalAndReadonly(): void
    {
        foreach ([ToolContext::class, GroundedToolContext::class] as $class) {
            $reflection = new \ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), $class . ' must be final');
            self::assertTrue($reflection->isReadOnly(), $class . ' must be readonly');
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Tool/Factory/ToolAuthorityTest.php`
Expected: FAIL — `Class "…ToolContext" not found`.

- [ ] **Step 3: Create the unprivileged context**

`src/Core/Tool/Factory/ToolContext.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What a contributed tool gets by default: somewhere to record what it did, and the merchant's
 * settings.
 *
 * **Deliberately not the gateway or the renderer.** `VISION.md`'s first non-negotiable is that the
 * model is structurally incapable of inventing a price, which holds only while catalogue facts reach
 * it through exactly one path. A tool that could obtain a `ProductCard` could hand the model a raw
 * price, and "structurally" would become "by convention".
 *
 * This is the right context for a store locator, an FAQ lookup, a shipping estimate — anything whose
 * answer is not a catalogue fact. A tool that genuinely needs the catalogue implements
 * {@see GroundedToolFactoryInterface} instead, and takes on the grounding duty its name describes.
 *
 * **Do not add properties here.** `ToolAuthorityTest` asserts this class's exact property list for
 * that reason: widening it is how the guarantee above would quietly end.
 *
 * @api
 */
final readonly class ToolContext
{
    public function __construct(
        public TraceRecorder $trace,
        public AssistantConfig $config,
    ) {}
}
```

- [ ] **Step 4: Create the grounded context**

`src/Core/Tool/Factory/GroundedToolContext.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Everything one turn's catalogue-facing tools share — and they share *these instances*, not
 * equivalent ones.
 *
 * That is ruling R32, and it is load-bearing rather than tidy: `AddToCartTool` reads
 * `gateway->cart()->total` live to enforce `maxCartValue`, so a tool holding its own gateway would
 * see an empty cart on every call and a model could drip-feed past the limit one item at a time —
 * exactly how a model would do it. One instance per request, built once by
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory::create()} and handed to every
 * factory.
 *
 * A tool built from this context **must** render shopper-facing facts through {@see FactRenderer}
 * rather than returning them itself. Nothing enforces that mechanically — which is the whole reason
 * the privileged tier is a separate, louder interface rather than the default.
 *
 * @api
 */
// @mago-expect lint:excessive-parameter-list
// One property per collaborator a catalogue-facing tool needs, and the point of the class is that
// they arrive together as one request's set. Grouping them behind a sub-object would hide the very
// thing R32 is about.
final readonly class GroundedToolContext
{
    public function __construct(
        public CommerceGatewayInterface $gateway,
        public TraceRecorder $trace,
        public AssistantConfig $config,
        public FactRenderer $renderer,
        public FacetProbe $facetProbe,
        public BlocklistFilter $blocklist,
        public VariantResolver $variantResolver,
        public QueryBuilder $queryBuilder,
        public bool $cartAvailable,
    ) {}
}
```

- [ ] **Step 5: Create both factory interfaces**

`src/Core/Tool/Factory/ToolFactoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

/**
 * Contributes one tool to a turn. Tag the implementation `swag_assistant.tool_factory`.
 *
 * **A factory rather than a service**, because tools are built per request around one shared gateway,
 * trace and renderer (R32) — a stateless tagged service could not hold them, and one that held them
 * across requests would leak one shopper's retrieved set into another's turn, which is the single
 * worst thing this pipeline could do.
 *
 * Returning `null` means "not this turn": that is how `enableAddToCart` and `enableEscalation` are
 * enforced, and it is the mechanism a contributed tool should use for its own switch. Capability
 * control is toolbox construction, never a prompt instruction (D6) — a tool that is never
 * constructed never appears in the schema the model sees.
 *
 * The return type is `object` because Symfony AI tools are plain classes carrying `#[AsTool]`, with
 * no common interface. That is the framework's contract and ADR 0001 accepted it knowingly.
 *
 * @api
 */
interface ToolFactoryInterface
{
    public function create(ToolContext $context): ?object;
}
```

`src/Core/Tool/Factory/GroundedToolFactoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

/**
 * Contributes one **catalogue-facing** tool to a turn. Tag it `swag_assistant.grounded_tool_factory`.
 *
 * The separate name is the warning. A tool built from a {@see GroundedToolContext} can reach the
 * gateway, so it can obtain real prices and stock — and it therefore inherits the duty that makes
 * `VISION.md`'s first non-negotiable true: shopper-facing facts are rendered through
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}, never returned raw to the model.
 *
 * If your tool does not need catalogue data, implement {@see ToolFactoryInterface} instead. It cannot
 * reach the gateway, which means it cannot get this wrong.
 *
 * @api
 */
interface GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object;
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Tool/Factory/ToolAuthorityTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite and gate**

Run: `vendor/bin/phpunit --no-coverage --exclude-group eval && vendor/bin/mago fmt && composer run quality`
Expected: pass; `quality` exits 0. Nothing else changed yet, so a failure here is a lint complaint
about the new files, not a regression.

- [ ] **Step 8: Commit**

```bash
git add src/Core/Tool/Factory tests/Core/Tool/Factory
git commit -m "feat: two tiers of tool authority, enforced by type"
```

---

### Task 2: Migrate the shipped tools onto factories and make the agent factory a service

The load-bearing task. It moves the four shipped tools onto the new interfaces, which is what proves
the interfaces fit, and it converts `AssistantAgentFactory` from a static method to a service without
loosening R32.

**Files:**
- Create: `src/Core/Tool/Factory/EscalateToolFactory.php`
- Create: `src/Core/Tool/Factory/SearchProductsToolFactory.php`
- Create: `src/Core/Tool/Factory/GetProductToolFactory.php`
- Create: `src/Core/Tool/Factory/AddToCartToolFactory.php`
- Modify: `src/Core/Agent/AssistantAgentFactory.php`
- Modify: `src/Core/Agent/ShopwareChatTurnRunner.php`, `src/Command/ProbeTurnRunner.php`, `src/Eval/JourneyAttempt.php`
- Modify: `src/Resources/config/services.xml`
- Test: `tests/Core/Agent/AssistantAgentFactoryTest.php`, `tests/Core/Agent/AssistantRunnerTest.php`, `tests/Core/Agent/AssistantVocabularyWiringTest.php`
- Test: `tests/Core/Agent/ContributedToolTest.php` (create)

**Interfaces:**
- Consumes: Task 1's contexts and interfaces.
- Produces: `AssistantAgentFactory::__construct(iterable $toolFactories, iterable $groundedToolFactories, PromptProviderInterface $prompt, LlmPlatformInterface $platform)`, `AssistantAgentFactory::withCoreToolsOnly(?HttpClientInterface $http = null): self`, `AssistantAgentFactory::create(CommerceGatewayInterface $gateway, AssistantConfig $config, bool $cartAvailable, LlmSettings $llm): Bundle`.

> **Ordering note:** this task depends on `PromptProviderInterface` and `LlmPlatformInterface` from
> Tasks 3 and 4. Do Tasks 3 and 4 **first** if you are executing out of order; the constructor
> signature above is the one they produce. Executed in order, introduce the two interfaces as part of
> this task's Step 5 and let Tasks 3 and 4 add their tests and decoration examples.

- [ ] **Step 1: Write the failing test for the contributed-tool path**

Create `tests/Core/Agent/ContributedToolTest.php`. Two properties matter and neither is about the
shipped tools:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * What an agency's own tool actually gets, asserted through the real factory.
 *
 * The interfaces in Task 1 are types; these are the two behaviours that make them worth having.
 */
final class ContributedToolTest extends TestCase
{
    use UsesCatalogFixture;

    public function testAContributedToolReachesTheToolboxTheModelSees(): void
    {
        $factory = $this->factoryWith(
            toolFactories: [new RecordingToolFactory()],
            groundedToolFactories: [],
        );

        $bundle = $factory->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        $names = array_map(static fn($tool): string => $tool->getName(), [...$bundle->toolbox->getTools()]);

        self::assertContains('recording_probe', $names);
    }

    public function testEveryToolInOneTurnSharesOneGatewayInstance(): void
    {
        // **Ruling R32, and the reason this whole task is risky.** AddToCartTool reads
        // gateway->cart()->total live to enforce maxCartValue, so a tool holding its own gateway sees
        // an empty cart on every call and a model can drip-feed past the limit one item at a time.
        // A contributed factory must be handed the same instance the shipped ones get.
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $first = new CapturingGroundedToolFactory();
        $second = new CapturingGroundedToolFactory();

        $this->factoryWith(toolFactories: [], groundedToolFactories: [$first, $second])->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: true,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        self::assertSame($gateway, $first->context?->gateway);
        self::assertSame($first->context?->gateway, $second->context?->gateway);
        self::assertSame($first->context?->trace, $second->context?->trace);
        self::assertSame($first->context?->renderer, $second->context?->renderer);
    }

    public function testAFactoryReturningNullContributesNothing(): void
    {
        // How enableAddToCart and enableEscalation work, and the mechanism a contributed tool should
        // use for its own switch: never constructed means never in the schema the model sees (D6).
        $bundle = $this->factoryWith(
            toolFactories: [new DecliningToolFactory()],
            groundedToolFactories: [],
        )->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        $names = array_map(static fn($tool): string => $tool->getName(), [...$bundle->toolbox->getTools()]);

        self::assertNotContains('declining_probe', $names);
    }

    /**
     * @param list<ToolFactoryInterface>         $toolFactories
     * @param list<GroundedToolFactoryInterface> $groundedToolFactories
     */
    private function factoryWith(array $toolFactories, array $groundedToolFactories): AssistantAgentFactory
    {
        // withCoreToolsOnly() plus extras, so the shipped four are present exactly as a real shop has
        // them — a test that dropped them would not be testing the same object the storefront builds.
        return AssistantAgentFactory::withCoreToolsOnly(new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called by this test.');
        }))->withAdditionalFactories($toolFactories, $groundedToolFactories);
    }
}
```

And the three doubles, in the same directory as separate files so the test class stays readable —
`tests/Core/Agent/RecordingToolFactory.php`, `tests/Core/Agent/CapturingGroundedToolFactory.php`,
`tests/Core/Agent/DecliningToolFactory.php`:

```php
// RecordingToolFactory.php
final class RecordingToolFactory implements ToolFactoryInterface
{
    public function create(ToolContext $context): ?object
    {
        return new #[AsTool(name: 'recording_probe', description: 'A test tool.')] class {
            public function __invoke(): string
            {
                return 'ok';
            }
        };
    }
}
```

An anonymous class cannot carry an attribute in PHP 8.2 in a way Symfony AI's metadata reader will
find reliably; declare a small named tool class beside each factory instead:

```php
#[AsTool(name: 'recording_probe', description: 'A test tool.')]
final class RecordingProbeTool
{
    public function __invoke(): string
    {
        return 'ok';
    }
}
```

`CapturingGroundedToolFactory` records the context it was handed and returns a
`RecordingProbeTool`-style tool of its own; `DecliningToolFactory::create()` returns `null`. Verify
against `tests/Core/Agent/BoundedToolboxTest.php` how it names tools before finalising these — that
file is the reference for what Symfony AI's `getTools()` yields.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Agent/ContributedToolTest.php`
Expected: FAIL — `withCoreToolsOnly()` and `withAdditionalFactories()` do not exist, and
`AssistantAgentFactory::create()` is still static.

- [ ] **Step 3: Write the four shipped factories**

`src/Core/Tool/Factory/EscalateToolFactory.php` — the only shipped tool that fits the unprivileged
tier, which is itself the demonstration that the tier is real:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Tool\EscalateTool;

/**
 * The shipped proof that the unprivileged tier is usable: escalation needs somewhere to record what
 * it did and the merchant's settings, and nothing from the catalogue at all.
 */
final readonly class EscalateToolFactory implements ToolFactoryInterface
{
    public function create(ToolContext $context): ?object
    {
        // Null rather than a disabled tool: never constructed means never in the schema the model
        // sees, which is what keeps capability control out of the prompt (D6).
        if (!$context->config->enableEscalation) {
            return null;
        }

        return new EscalateTool($context->trace, $context->config);
    }
}
```

`SearchProductsToolFactory`:

```php
    public function create(GroundedToolContext $c): ?object
    {
        return new SearchProductsTool(
            $c->gateway,
            $c->facetProbe,
            $c->queryBuilder,
            $c->variantResolver,
            $c->blocklist,
            $c->renderer,
            $c->trace,
            $c->config,
        );
    }
```

`GetProductToolFactory`:

```php
    public function create(GroundedToolContext $c): ?object
    {
        return new GetProductTool($c->gateway, $c->variantResolver, $c->blocklist, $c->renderer, $c->trace, $c->config);
    }
```

`AddToCartToolFactory`:

```php
    public function create(GroundedToolContext $c): ?object
    {
        // Both halves of today's condition, unchanged: the merchant's switch, and whether this
        // request even has a shopper cart to add to.
        if (!$c->config->enableAddToCart || !$c->cartAvailable) {
            return null;
        }

        return new AddToCartTool($c->gateway, $c->blocklist, $c->renderer, $c->trace, $c->config);
    }
```

Give each a docblock in the codebase's voice; the four above show the bodies, not the prose.

- [ ] **Step 4: Rewrite `AssistantAgentFactory` as a service**

Replace the static `create()` with an instance method, keeping every existing comment about R32,
`BoundedToolbox`, `AgentProcessor`'s inert cap and processor ordering — those are measured findings and
must survive the refactor verbatim. The new shape:

```php
final readonly class AssistantAgentFactory
{
    /**
     * @param iterable<ToolFactoryInterface>         $toolFactories
     * @param iterable<GroundedToolFactoryInterface> $groundedToolFactories
     */
    public function __construct(
        private iterable $toolFactories,
        private iterable $groundedToolFactories,
        private PromptProviderInterface $prompt,
        private LlmPlatformInterface $platform,
    ) {}

    /**
     * The shipped assistant, with no container involved.
     *
     * `Eval\JourneyAttempt`, `ProbeTurnRunner` and the unit tests all build the pipeline without
     * Symfony, and that is deliberate rather than incidental: the eval suite's selling point is that
     * it needs no Shopware and no database and runs in seconds. This keeps that true while the
     * storefront gets the container's tagged factories instead.
     */
    public static function withCoreToolsOnly(?HttpClientInterface $http = null): self
    {
        return new self(
            [new EscalateToolFactory()],
            [new SearchProductsToolFactory(), new GetProductToolFactory(), new AddToCartToolFactory()],
            new SystemPromptProvider(),
            new SymfonyAiPlatform($http),
        );
    }

    /**
     * @param list<ToolFactoryInterface>         $toolFactories
     * @param list<GroundedToolFactoryInterface> $groundedToolFactories
     */
    public function withAdditionalFactories(array $toolFactories, array $groundedToolFactories): self
    {
        return new self(
            [...$this->toolFactories, ...$toolFactories],
            [...$this->groundedToolFactories, ...$groundedToolFactories],
            $this->prompt,
            $this->platform,
        );
    }

    public function create(
        CommerceGatewayInterface $gateway,
        AssistantConfig $config,
        bool $cartAvailable,
        LlmSettings $llm,
    ): Bundle {
        // ... existing body: one TraceRecorder, one FactRenderer, one FacetProbe, the vocabulary
        // probe and its trace event, all unchanged and all still built exactly once here. That is
        // what keeps R32 true: this method still owns the per-request singletons, and the factories
        // below receive them rather than making their own.

        $context = new ToolContext($trace, $config);
        $groundedContext = new GroundedToolContext(
            gateway: $gateway,
            trace: $trace,
            config: $config,
            renderer: $renderer,
            facetProbe: $facetProbe,
            blocklist: $blocklist,
            variantResolver: $variantResolver,
            queryBuilder: $queryBuilder,
            cartAvailable: $cartAvailable,
        );

        $tools = [];
        foreach ($this->groundedToolFactories as $factory) {
            $tools[] = $factory->create($groundedContext);
        }
        foreach ($this->toolFactories as $factory) {
            $tools[] = $factory->create($context);
        }
        $tools = array_values(array_filter($tools));

        // ... existing BoundedToolbox / AgentProcessor / Agent construction, with
        // PlatformFactory::create($llm, $http) replaced by $this->platform->of($llm) and
        // SystemPrompt::build(...) replaced by the provider, called from AssistantRunner as today.
    }
}
```

Grounded factories run before unprivileged ones so the shipped tool order — search, get, cart, then
escalate — is unchanged; a reordered toolbox changes which tool a model reaches for first, and that is
not a change to make accidentally inside a refactor.

- [ ] **Step 5: Introduce the two collaborator interfaces**

Create `src/Core/Prompt/PromptProviderInterface.php` with
`system(AssistantConfig $config, string $vocabulary): string`, and
`src/Core/Prompt/SystemPromptProvider.php` delegating to the existing `SystemPrompt::build()`. Create
`src/Core/Llm/LlmPlatformInterface.php` with `of(LlmSettings $settings): PlatformInterface`, and
`src/Core/Llm/SymfonyAiPlatform.php` wrapping the existing `PlatformFactory::create()` and holding the
optional `HttpClientInterface`. Tasks 3 and 4 add their tests and prove the decoration works; this
step only creates what Step 4's constructor needs.

`AssistantRunner` builds the prompt today via `SystemPrompt::build($this->config, ...)`. Thread the
provider through `Bundle` — add a `PromptProviderInterface $prompt` property to it — so `AssistantRunner`
asks the bundle rather than a static, and a decorated provider actually reaches the turn.

- [ ] **Step 6: Update the six call sites**

- `ShopwareChatTurnRunner`: take `AssistantAgentFactory` as a constructor argument and call `$this->agentFactory->create(...)`.
- `ProbeTurnRunner` and `Eval\JourneyAttempt`: `AssistantAgentFactory::withCoreToolsOnly($this->http)->create(...)`.
- `AssistantAgentFactoryTest`, `AssistantRunnerTest`, `AssistantVocabularyWiringTest`: same, via each file's existing helper.

- [ ] **Step 7: Wire the container**

In `src/Resources/config/services.xml`: register the four shipped factories, tag them, register
`SystemPromptProvider` and `SymfonyAiPlatform`, then:

```xml
        <service id="Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory">
            <argument type="tagged_iterator" tag="swag_assistant.tool_factory"/>
            <argument type="tagged_iterator" tag="swag_assistant.grounded_tool_factory"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface"/>
            <argument type="service" id="Swag\AssistantStarterKit\Core\Llm\LlmPlatformInterface"/>
        </service>
```

with `PromptProviderInterface` aliased to `SystemPromptProvider` and `LlmPlatformInterface` to
`SymfonyAiPlatform`, matching how `CommerceGatewayInterface` and `ConversationStore` are already
aliased — that alias is what makes decoration work for an extender.

- [ ] **Step 8: Run the contributed-tool test, then everything**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Agent/ContributedToolTest.php`
Expected: PASS, including the R32 assertion.

Run: `vendor/bin/phpunit --no-coverage --exclude-group eval`
Expected: PASS. `AssistantAgentFactoryTest`'s existing add-to-cart accumulation test is the other R32
guard and must stay green — if it fails, a tool is getting its own gateway.

Run: `vendor/bin/mago fmt && composer run quality`
Expected: exits 0.

- [ ] **Step 9: Verify a real turn still works**

The unit suite cannot catch a container that will not compile. In the local docker shop:

```bash
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console cache:clear'
docker exec shopping-assistant-test-web-1 bash -lc 'curl -s -X POST http://127.0.0.1:8000/assistant/chat \
  -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" \
  -d "{\"message\":\"do you have a water bottle?\"}" --max-time 120'
```

Expected: HTTP 200 with cards. A container error surfaces here as a 500 on every storefront page, not
just the assistant, so this step is not optional.

- [ ] **Step 10: Run one eval journey**

Run: `vendor/bin/phpunit --group eval --filter cart_add --no-coverage`
Expected: PASS. `cart_add` exercises the grounded tools and the cart limit — the path R32 protects.
Costs 6 live turns.

- [ ] **Step 11: Commit**

```bash
git add src/Core/Tool/Factory src/Core/Agent src/Core/Prompt src/Core/Llm src/Command \
        src/Eval/JourneyAttempt.php src/Resources/config/services.xml tests/Core/Agent
git commit -m "feat: contribute tools through the container instead of forking"
```

---

### Task 3: Prove the prompt seam by decorating it

Task 2 created `PromptProviderInterface` because its constructor needed one. This task proves an
extender can actually replace it, which is a different claim.

**Files:**
- Test: `tests/Core/Prompt/PromptProviderDecorationTest.php` (create)
- Modify: `README.md`

- [ ] **Step 1: Write the failing test**

```php
    public function testADecoratedProviderReachesTheTurn(): void
    {
        // The claim being tested is not "the interface exists" but "replacing it changes the prompt
        // the model is actually sent". ARCHITECTURE documented six interfaces that did not exist;
        // an interface nothing consults is the same defect wearing a type.
        $provider = new class implements PromptProviderInterface {
            public function system(AssistantConfig $config, string $vocabulary): string
            {
                return 'REPLACED';
            }
        };

        $bundle = (new AssistantAgentFactory(
            [], [], $provider, new SymfonyAiPlatform($this->refusingHttpClient()),
        ))->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        self::assertSame('REPLACED', $bundle->prompt->system(new AssistantConfig(), ''));
    }
```

- [ ] **Step 2: Run it to verify it fails, then make it pass**

Run: `vendor/bin/phpunit --no-coverage tests/Core/Prompt/PromptProviderDecorationTest.php`

If it fails because `Bundle` has no `prompt` property, Task 2 Step 5 was not finished — go back and
thread the provider through `Bundle`, because without that a decorated provider never reaches
`AssistantRunner` and the seam is decorative.

- [ ] **Step 3: Document it, run the gate, commit**

Add the prompt row to the README's extension-points table (Task 6 rewrites that table wholesale;
this step only ensures the row exists if Task 6 is deferred).

```bash
vendor/bin/phpunit --no-coverage --exclude-group eval && vendor/bin/mago fmt && composer run quality
git add tests/Core/Prompt README.md
git commit -m "test: prove a replaced prompt provider reaches the turn"
```

---

### Task 4: Prove the platform seam

**Files:**
- Test: `tests/Core/Llm/LlmPlatformDecorationTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
    public function testAReplacedPlatformIsTheOneTheAgentUses(): void
    {
        // Symfony AI ships 35+ platform bridges and this plugin could reach none of them: 
        // PlatformFactory::create() was static and pinned the generic OpenAI-compatible one. The
        // assertion is that a replacement is actually consulted, not merely accepted.
        $platform = new class implements LlmPlatformInterface {
            public int $calls = 0;

            public function of(LlmSettings $settings): PlatformInterface
            {
                $this->calls++;

                return PlatformFactory::create($settings, new MockHttpClient());
            }
        };

        (new AssistantAgentFactory([], [], new SystemPromptProvider(), $platform))->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
        );

        self::assertSame(1, $platform->calls, 'the factory must ask the injected platform, not a static');
    }
```

- [ ] **Step 2: Run, verify, gate, commit**

```bash
vendor/bin/phpunit --no-coverage tests/Core/Llm/LlmPlatformDecorationTest.php
vendor/bin/phpunit --no-coverage --exclude-group eval && vendor/bin/mago fmt && composer run quality
git add tests/Core/Llm
git commit -m "test: prove a replaced LLM platform is the one the agent uses"
```

---

### Task 5: Trace sinks, and the first shipped one

Closes Linear's "analytics sinks" extension point and half of its "analytics destinations"
configurable, with a consumer in-tree so the interface is not a guess.

**Files:**
- Create: `src/Core/Trace/Sink/TraceSinkInterface.php`
- Create: `src/Core/Trace/Sink/LoggerTraceSink.php`
- Create: `src/Core/Trace/Sink/TraceSinkDispatcher.php`
- Modify: `src/Core/Policy/AssistantConfig.php`, `src/Core/Config/SystemConfigAssistantConfig.php`, `src/Resources/config/config.xml`, `tests/PluginManifestTest.php`
- Modify: `src/Controller/AssistantController.php`, `src/Resources/config/services.xml`
- Test: `tests/Core/Trace/Sink/TraceSinkDispatcherTest.php`, `tests/Controller/AssistantEndpointTestCase.php`

**Interfaces:**
- Produces: `TraceSinkInterface::send(string $token, string $salesChannelId, TraceRecorder $trace): void`; `TraceSinkDispatcher::dispatch(...)` with the same signature; `AssistantConfig::$logTraces` (bool, default **false**).

- [ ] **Step 1: Write the failing test**

```php
    public function testAThrowingSinkCannotBreakADeliveredTurn(): void
    {
        // The turn already succeeded and its trace is already persisted when sinks run. A broken
        // analytics integration turning a delivered answer into a 500 would be the worst possible
        // trade, so the dispatcher swallows and traces instead.
        $trace = new TraceRecorder();
        $exploding = new class implements TraceSinkInterface {
            public function send(string $token, string $salesChannelId, TraceRecorder $trace): void
            {
                throw new \RuntimeException('sink is down');
            }
        };
        $healthy = new RecordingTraceSink();

        (new TraceSinkDispatcher([$exploding, $healthy]))->dispatch('token', 'channel', $trace);

        // And one failing sink must not stop the others: they are independent destinations.
        self::assertSame(1, $healthy->calls);
    }

    public function testEachSinkReceivesTheTurnsTrace(): void
    {
        $sink = new RecordingTraceSink();
        $trace = new TraceRecorder();
        $trace->record('turn.end', ['outcome' => 'product_shown']);

        (new TraceSinkDispatcher([$sink]))->dispatch('tok', 'chan', $trace);

        self::assertSame('tok', $sink->lastToken);
        self::assertSame($trace, $sink->lastTrace);
    }
```

Plus `tests/Core/Trace/Sink/RecordingTraceSink.php` holding `int $calls`, `?string $lastToken`,
`?TraceRecorder $lastTrace`.

- [ ] **Step 2: Run it to verify it fails**

Expected: FAIL — `TraceSinkInterface` and `TraceSinkDispatcher` do not exist.

- [ ] **Step 3: Implement the interface, dispatcher and logger sink**

`TraceSinkInterface` — one method, `@api`, tagged `swag_assistant.trace_sink`, docblock stating that
sinks run synchronously after persistence and that a slow sink costs the shopper latency.

`TraceSinkDispatcher` — iterates, wraps each `send()` in try/catch, and on failure records a
`trace.sink.failed` event with the sink's class and the exception message. Note in its docblock that
this event goes to a trace **already written**, so it is visible in the next turn's rows rather than
this one's; the alternative was failing a delivered answer.

`LoggerTraceSink` — takes a PSR-3 `LoggerInterface` (already a dependency) and the `logTraces` flag,
returns early when the flag is off, and otherwise logs one line per turn at `info` with the token, the
sales channel, the outcome and the elapsed ms. **No shopper text**: the transcript is personal data
and a log file has neither the retention task nor the access control the conversation table has.

- [ ] **Step 4: Add the config field**

`logTraces` bool, default **false**, in a `config.xml` card titled "Analytics", read with `boolOr`.
Help text: what it writes, that it writes no shopper text, and that it is the shipped example of the
`swag_assistant.trace_sink` tag rather than a full analytics integration. Add `logTraces` to
`PluginManifestTest`'s required list.

- [ ] **Step 5: Dispatch from the controller, after persistence**

In `AssistantController::chat()`, after both `append()` calls:

```php
        // After persistence, never before: a sink is a third party, and one that throws must not cost
        // the merchant the audit row that was the point of recording the turn.
        $this->traceSinks->dispatch($token, $salesChannelId, $result->trace);
```

Add `TraceSinkDispatcher $traceSinks` to the constructor and to `AssistantEndpointTestCase::controller()`.

- [ ] **Step 6: Wire, gate, verify a real turn, commit**

Register the dispatcher with a `tagged_iterator`, register `LoggerTraceSink` with `monolog.logger` and
the tag. Then:

```bash
vendor/bin/phpunit --no-coverage --exclude-group eval && vendor/bin/mago fmt && composer run quality
docker exec shopping-assistant-test-web-1 bash -lc 'cd /var/www/html && php bin/console system:config:set SwagAssistantStarterKit.config.logTraces true && php bin/console cache:clear'
# then one real turn, and confirm a line appears in var/log
git add src/Core/Trace/Sink src/Core/Policy src/Core/Config src/Resources src/Controller tests
git commit -m "feat: contribute a trace sink through the container"
```

---

### Task 6: Make the extension-points documentation true

**Files:**
- Modify: `ARCHITECTURE.md`, `README.md`
- Create: `docs/extending.md`

- [ ] **Step 1: Rewrite the ARCHITECTURE extension-points table**

The table currently carries a correction notice saying six named interfaces do not exist. Four now do.
Move those four into the "genuinely extensible" table with their tags, leave ranking rules, knowledge
sources and MCP in the "requires editing this plugin" table with what each would need, and **keep the
correction notice** — rewritten to say what was wrong, what is now true, and that the remaining three
are still absent. Deleting it would erase the reason the rule "every interface ships with a consumer"
exists.

- [ ] **Step 2: Write `docs/extending.md` with one worked example of each seam**

Four short, complete, copy-pasteable examples: a store-locator tool on the unprivileged tier; a
catalogue-facing tool on the grounded tier with a note that it owes `FactRenderer` its facts; a
decorated prompt provider; a trace sink posting to an HTTP endpoint. Each with its `services.xml`
snippet. This file is what the acceptance signal actually rests on — an agency reads this, not the
architecture doc.

- [ ] **Step 3: Point the README at it**

Replace the README's storefront-only "Extension points" section with a short table of all five seams
(the four new ones plus the Twig blocks) linking to `docs/extending.md`.

- [ ] **Step 4: Gate and commit**

```bash
composer run quality
git add ARCHITECTURE.md README.md docs/extending.md
git commit -m "docs: an extension guide that matches the code"
```

---

## Self-review notes

**Spec coverage.** Linear's seven extension points: tools → Tasks 1–2, prompts → Tasks 2–3, analytics
sinks → Task 5, storefront UI → already shipped. Ranking rules, knowledge sources and MCP are
explicitly out, with the reason recorded in *Design of record* and in Task 6's surviving correction
notice. "Analytics destinations" as a *configurable* is half-closed by Task 5's `logTraces`; a
merchant-configurable external destination still needs a shipped HTTP sink, which is not in this plan.

**Type consistency.** `ToolFactoryInterface::create(ToolContext): ?object` and
`GroundedToolFactoryInterface::create(GroundedToolContext): ?object` are used with those exact
signatures in Tasks 1, 2 and 6. `AssistantAgentFactory::create()` drops `$http` and takes four
parameters everywhere it appears. `PromptProviderInterface::system(AssistantConfig, string): string`
and `LlmPlatformInterface::of(LlmSettings): PlatformInterface` match between Tasks 2, 3 and 4.

**The dependency inversion in Task 2 is real and flagged inline:** its constructor needs the two
interfaces that Tasks 3 and 4 are named after. Task 2 Step 5 creates them; Tasks 3 and 4 prove they
are consulted. An executor working out of order must do 3 and 4 first.

**Three things an executor must verify rather than trust,** because this plan did not run them: how
Symfony AI's `Toolbox::getTools()` names tools and whether a named tool class is required rather than
an anonymous one (Task 2 Step 1 — read `BoundedToolboxTest`); whether `Bundle` can carry the prompt
provider without breaking `OutputProcessorOrderTest` (Task 2 Step 5); and the correct Shopware service
id for a PSR-3 logger in a plugin context (Task 5 Step 6).

**Risk.** Task 2 is the only genuinely dangerous step in this plan: it rewrites the method that
guarantees R32, and getting it wrong reintroduces the cart-limit bypass and, worse, the possibility of
one shopper's retrieved set reaching another's turn. It carries three guards — the new R32 test, the
existing add-to-cart accumulation test, and a live `cart_add` journey. Do not let it land without all
three green. **Not a task to run the day before a demo.**
