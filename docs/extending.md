# Extending the assistant

How to give the assistant a capability it does not have. Twelve seams, most with a working example
you can copy, all of them ordinary Symfony service wiring — and each works the same whether you are
adding it to this repository or shipping it in a plugin of your own.

> **Research preview.** These interfaces carry `@api` to record intent, not to promise stability.
> They will move. What will not change is that they exist and have shipped consumers in this
> repository, which is the property their absence used to break.

| I want to… | Seam | Tag or mechanism |
|---|---|---|
| Add a tool that answers from my own data | `ToolFactoryInterface` | `swag_assistant.tool_factory` |
| Add a tool that answers from the catalogue | `GroundedToolFactoryInterface` | `swag_assistant.grounded_tool_factory` |
| Change the system prompt | `PromptProviderInterface` | decorate the service |
| Use a different model provider | `LlmPlatformInterface` | decorate the service |
| Send turns to my analytics | `TraceSinkInterface` | `swag_assistant.trace_sink` |
| Read a document format we do not | `TextExtractor` | `swag_assistant.text_extractor` |
| Embed with a different model | `Embedder` | decorate or replace the service |
| Store vectors somewhere else | `PassageStore` | replace or decorate the service |
| Assert something about my own tool in an eval journey | `Assertion` | name the class in the journey's `assertions` map |
| Change the widget's markup | Twig blocks | template override, see [the manual](manual.md#the-storefront-widget) |
| Drive the widget from your own JS | `swag-assistant:*` DOM events | listen on / dispatch at the widget root |
| Swap the catalogue backend entirely | `CommerceGatewayInterface` **plus four optional capability interfaces** | decorate the service — [read this first](#swapping-the-catalogue-backend) |

The four tags are collected with `tagged_iterator`, so a service in *your* plugin is found the same
way the shipped ones are. Nothing needs to be registered with us.

## Where your code lives

The seams do not care, which is the point. But the two paths differ in three small ways, and the
examples below have to pick one — they use an `Acme\` namespace to keep the boundary visible.

**Adding a capability to the starter kit itself.** Put the class beside its siblings — a tool factory
in `src/Core/Tool/Factory/`, an extractor in `src/Core/ShopInfo/Extractor/` — and register it in this
plugin's own `src/Resources/config/services.xml` carrying the same tag the shipped ones carry. Read
`Acme\Assistant\…` as `Swag\AssistantStarterKit\…` throughout; nothing else changes. You may also
just inject a list where a tag would go, but don't: the tag is what keeps the next contributor from
having to find and edit a constructor.

**Shipping a capability as your own plugin.** Your code lives in your own `shopware-platform-plugin`
with its own `composer.json` and `services.xml`. Two things make that work, and neither is obvious
from the examples:

- Shopware plugins are Symfony bundles sharing **one** container. That is the entire reason a tag
  crosses a plugin boundary — `tagged_iterator` collects your service exactly as it collects ours,
  with nothing registered on our side.
- **Your plugin must require this one**, and the require earns more than it looks like. Without it,
  your tagged service sits in a shop where nothing collects that tag: no error, no log line, your
  tool simply never reaches the model — the failure mode this whole document exists to make findable.
  *With* it, Shopware reads the `composer.json` require as a plugin dependency and refuses to
  deactivate us underneath you: `PluginHasActiveDependantsException`, naming your plugin. Verified on
  6.7.13.1 with a plugin installed by hand into `custom/plugins/`, so it holds even where Composer
  never resolved the constraint. One line in `require` converts a silent degradation into a loud
  refusal.

Decoration — `PromptProviderInterface`, `LlmPlatformInterface`, `CommerceGatewayInterface` — works
from either side, and from a separate plugin it is why no fork is needed.

## What already ships

Before you write a tool, know what you would be duplicating. Six ship, each behind its own factory,
and four of them are switchable by the merchant:

| Tool | Tier | Present when |
|---|---|---|
| `search_products` | grounded | always |
| `get_product` | grounded | always |
| `add_to_cart` | grounded | `enableAddToCart` |
| `compare_products` | grounded | `enableCompareProducts` (off by default) |
| `search_shop_info` | plain | an `embeddingModel` is configured |
| `escalate` | plain | `enableEscalation` |

`search_products` also carries two opt-in behaviours worth knowing about because they change what the
model is handed: `enableMatchReasons` (deterministic reason codes for *why* a product was retrieved)
and the bounded `properties` list every product summary now includes.

A factory returning `null` is how all four switches work, and it is the pattern to copy: a tool that
is never constructed is never in the schema the model sees, which keeps capability control out of the
prompt. A tool the model can see is a tool it will try, and a refusal reads to a shopper as a failure.

## Why tools are factories, not services

A turn's tools share **one** gateway, one trace recorder and one fact renderer, and they must share
the same *instances*. `AddToCartTool` reads the live cart total to enforce the merchant's
`maxCartValue`, so a tool holding its own gateway would see an empty cart on every call — and a model
could add one item at a time past the limit, which is exactly how a model would do it.

A stateless tagged service cannot hold per-request objects, and one that held them across requests
would leak one shopper's retrieved set into another's conversation. So you contribute a **factory**,
and it is handed the turn's context.

## Two tiers, and why yours is probably the first one

The assistant's core promise is that the model is *structurally* incapable of inventing a price:
prices, stock, URLs and images are rendered server-side from the retrieved record, so there is no path
by which the model can make one up. A tool that could return `['price' => 19.90]` would end that.

So there are two tiers:

- **`ToolFactoryInterface`** receives a `ToolContext`: the trace and the merchant's config. It cannot
  reach the catalogue, which means it cannot get the above wrong. Use this unless you need catalogue
  data — a store locator, an FAQ lookup, a shipping estimate, a warranty checker.
- **`GroundedToolFactoryInterface`** receives a `GroundedToolContext`, which is everything a shipped
  grounded tool is built from: `gateway`, `trace`, `config`, `renderer` (`FactRenderer`),
  `facetProbe`, `blocklist`, `variantResolver`, `queryBuilder`, `cartAvailable`, and
  `browsingCategoryId` — the category the shopper is currently browsing, or null. Use it when your
  tool genuinely answers from the catalogue, and render shopper-facing facts through `FactRenderer`
  rather than returning them yourself.

`browsingCategoryId` is client-supplied and never resolved, so **use it only to narrow.** As a
`ProductQuery::$categoryId` it is AND-ed with the merchant's scope and can only ever return fewer
products; putting it anywhere the scope is OR-ed — `CatalogScope::$includeCategoryIds` — would let a
shopper reach products the merchant excluded. The shipped `search_products` also retries without it
when the constraint leaves the shopper with nothing, and records `retrieve.without_category` when it
does.

The two context classes deliberately share no parent, so an unprivileged factory cannot cast its way
to the catalogue.

## Example 1: a tool that answers from your own data

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Tool;

use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(name: 'find_store', description: 'Find the nearest shop that stocks click-and-collect.')]
final class FindStoreTool
{
    public function __construct(private readonly StoreDirectory $directory) {}

    /** @param string $postcode The shopper's postcode. */
    public function __invoke(string $postcode): array
    {
        return ['stores' => $this->directory->near($postcode)];
    }
}

final readonly class FindStoreToolFactory implements ToolFactoryInterface
{
    public function __construct(private StoreDirectory $directory) {}

    public function create(ToolContext $context): ?object
    {
        // Return null to be absent this turn. Never construct a tool you then refuse to run: a tool
        // the model can see is a tool it will try, and a refusal reads to a shopper as a failure.
        //
        // `AssistantConfig` is a fixed shape and holds nothing of yours, so gate on your own
        // plugin's config (see "What is not extensible yet") or on the state your tool needs. This
        // one has no stores to offer if the directory is empty.
        if ($this->directory->isEmpty()) {
            return null;
        }

        return new FindStoreTool($this->directory);
    }
}
```

```xml
<service id="Acme\Assistant\Tool\FindStoreToolFactory">
    <argument type="service" id="Acme\Assistant\StoreDirectory"/>
    <tag name="swag_assistant.tool_factory"/>
</service>
```

Your tool's return value goes to the model as JSON. Keep it small — it is spent from the same context
budget as everything else in the turn.

**A turn your plain tool answered is recorded as `no_result`.** The outcome is derived from the
catalogue cards the turn rendered, and a plain tool renders none — so a shopper who got a perfectly
good store-locator answer shows up in the Administration's conversation list beside the turns that
found nothing. Your tool still appears in the trace, both as `tool.call` and as whatever stage you
record yourself, so the evidence is there; it is the summary column that misreads. Worth knowing
before a merchant asks you why your extension "never works".

## Example 2: a tool that answers from the catalogue

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(name: 'check_fitment', description: 'Check whether a product fits a given model year.')]
final class CheckFitmentTool
{
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly FactRenderer $renderer,
        private readonly BlocklistFilter $blocklist,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param string $productId The product to check.
     * @param string $forModel  The model year the shopper owns, e.g. "2019".
     */
    public function __invoke(string $productId, string $forModel): array
    {
        $card = $this->gateway->product($productId, $this->config->scope);

        if ($card === null) {
            return ['fits' => null, 'note' => 'No such product in this shop.'];
        }

        // 1. Blocklist first, and record what it took.
        $filtered = $this->blocklist->apply([$card], $this->config->scope);
        $this->trace->record('blocklist.filter', ['stage' => 'post', 'removedIds' => $filtered['removed']]);

        // 2. Register the survivors. This — not render() — is what makes them renderable.
        $this->renderer->registerRetrieved($filtered['cards']);

        if ($filtered['cards'] === []) {
            return ['fits' => null, 'note' => 'Product exists but is not available in this shop.'];
        }

        return [
            // Ids and names. Never a price, a stock level or a URL: the server substitutes those.
            'products' => ToolProductSummary::of($filtered['cards']),
            // Your own fact, which is the reason this tool exists.
            'fits' => $forModel >= '2015',
        ];
    }
}

final readonly class CheckFitmentToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        return new CheckFitmentTool(
            $context->gateway,
            $context->renderer,
            $context->blocklist,
            $context->trace,
            $context->config,
        );
    }
}
```

> As in Example 1, the tool and its factory are shown in one block for reading. **PSR-4 needs one
> class per file** — a plugin that pastes them into a single file will not autoload.

```xml
<service id="Acme\Assistant\Tool\CheckFitmentToolFactory">
    <tag name="swag_assistant.grounded_tool_factory"/>
</service>
```

Two obligations come with this tier, and neither is enforced mechanically. **The order matters** —
this is the sequence every shipped grounded tool follows, and `GetProductTool` is the shortest one to
read:

1. **Apply the blocklist first.** `$filtered = $context->blocklist->apply($cards, $context->config->scope)`
   — a merchant who excluded a product excluded it from your tool too, and a blocklist that a plugin
   can bypass does not survive a compliance review. It returns
   `array{cards: list<ProductCard>, removed: list<string>}`; record `removed` as `blocklist.filter`
   the way the shipped tools do.
2. **Register what survived, and return only ids and names.**
   `$context->renderer->registerRetrieved($filtered['cards'])`, then return
   `ToolProductSummary::of($filtered['cards'])`. Registering is what makes those ids *renderable*:
   the output processor validates the model's answer against the registered set and renders the cards
   from it, which is the whole grounding guarantee. **A tool that skips it renders nothing**, and the
   turn is recorded `no_result` with a perfectly good answer in the prose.

**Do not call `$context->renderer->render()` yourself.** Until 2026-09-02 this section told you to,
and it was wrong twice: the method takes the *accepted ids* the grounding step selected, not your
cards, and calling it is the output processor's job rather than the tool's. Your tool's job ends at
`registerRetrieved()`.

Three types you will import, because their namespaces are not guessable from the context object:
`Swag\AssistantStarterKit\Core\Policy\BlocklistFilter`,
`Swag\AssistantStarterKit\Core\Grounding\FactRenderer`, and the DTOs under
`Swag\AssistantStarterKit\Core\Commerce\Dto\`. A wrong import here is an **HTTP 500**, not a
degraded turn: factories are constructed while the agent is assembled, outside the
`AgentExceptionInterface` catch that protects the turn itself.

## Example 3: change the system prompt

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Prompt;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;

final readonly class AcmePromptProvider implements PromptProviderInterface
{
    public function __construct(private PromptProviderInterface $inner) {}

    public function system(AssistantConfig $config, string $vocabulary = '', string $viewing = ''): string
    {
        // Appending keeps the shipped rules. Replacing drops them - see below.
        return $this->inner->system($config, $vocabulary, $viewing)
            . "\n\nAlways mention that delivery to the islands takes two extra days.";
    }
}
```

```xml
<service id="Acme\Assistant\Prompt\AcmePromptProvider"
         decorates="Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface">
    <argument type="service" id=".inner"/>
</service>
```

`$viewing` is the line naming the product the shopper currently has open, already rendered and
already stripped of every figure. **Pass it on.** A provider that drops it compiles, passes its
tests and silently costs the assistant its page context — nothing fails, the model is simply no
longer told what "this" refers to.

**Decorate rather than replace unless you mean it.** The shipped prompt carries the injection
defence, the rule against claiming what the shop does not sell, and the escalation clause — and each
of those has an eval journey behind it. A wholesale replacement will fail those journeys, which is the
intended way to discover you dropped one. Run `composer run test:eval` after changing the prompt.

## Example 4: send turns to your analytics

The plugin ships one sink of its own — `LoggerTraceSink`, controlled by the **Logging** card's
`logTraces` setting, which writes one line per reply and no shopper text. That is an example of this
extension point rather than an analytics integration; the merchant-facing help text no longer
mentions the tag, because a service-container tag in a settings form is documentation in the wrong
place. Sinks are additive, so yours runs alongside it.

**It logs to a channel of its own, and the reason is worth borrowing.** Until 2026-09-02 it injected
the shop-wide `logger` and wrote at `info` — which a stock production Shopware discards, because its
file handler is `level: error` behind a `fingers_crossed` at `action_level: error`. The setting was
on, the sink ran, and nothing was ever written; in `dev` the line appeared, which is why it looked
fine. `TraceLogChannel` is now prepended onto the shop's `monolog` config and the service carries
`<tag name="monolog.logger" channel="swag_assistant"/>`. **If your sink logs rather than enqueues,
it has the same problem** — inject a channel of your own, or your integration is a setting that does
nothing.

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Analytics;

use Swag\AssistantStarterKit\Core\Trace\Sink\TraceSinkInterface;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final readonly class AcmeAnalyticsSink implements TraceSinkInterface
{
    public function __construct(private MessageBusInterface $bus) {}

    public function send(#[\SensitiveParameter] string $token, string $salesChannelId, TraceRecorder $trace): void
    {
        // Enqueue rather than call. A sink runs inside the shopper's request, so an HTTP call to your
        // analytics service is latency the shopper pays for.
        $this->bus->dispatch(new TurnFinished($token, $salesChannelId, $trace->events()));
    }
}
```

```xml
<service id="Acme\Assistant\Analytics\AcmeAnalyticsSink">
    <argument type="service" id="messenger.default_bus"/>
    <tag name="swag_assistant.trace_sink"/>
</service>
```

Three things to know:

- **Sinks run synchronously, after the trace is persisted.** A slow sink makes a shopper wait; keep
  `send()` to an enqueue.
- **A throwing sink is caught**, recorded as `trace.sink.failed`, and the other sinks still run. Your
  outage will not take the assistant down — but it also will not be loud, so monitor your own side.
- **Trace payloads quote shoppers.** Search terms are in there. If you forward events to a third
  party, that is personal data leaving the shop, with none of the retention the conversation table
  has. `LoggerTraceSink` ships as an example of the narrow alternative: an allowlist of four fields.

## Example 5: a different model provider

```php
final readonly class BedrockPlatform implements LlmPlatformInterface
{
    public function of(LlmSettings $settings): PlatformInterface
    {
        return \Symfony\AI\Platform\Bridge\Bedrock\PlatformFactory::create(/* ... */);
    }
}
```

```xml
<service id="Acme\Assistant\Llm\BedrockPlatform"
         decorates="Swag\AssistantStarterKit\Core\Llm\LlmPlatformInterface"/>
```

Settings arrive per call because they are per sales channel: one shop can point two storefronts at
two different models.

## Example 6: read a document format we do not

The shop-information feature answers policy questions ("how long do I have to return this?") from
documents the merchant uploads, plus the shop's own CMS legal pages, which are indexed automatically.
Five formats ship — `txt`, `md`, `html`, `pdf`, `docx` — behind one interface, tagged rather than
injected as a list precisely so a merchant with an in-house format adds a service and changes nothing
else.

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\TextExtractor;

final readonly class RtfExtractor implements TextExtractor
{
    public function supports(string $extension): bool
    {
        return $extension === 'rtf';
    }

    public function extract(string $bytes): string
    {
        $text = $this->toPlainText($bytes);

        // Throw for a malformed file or one holding no text. Returning '' instead indexes an empty
        // document, and the merchant is told the upload succeeded.
        if (trim($text) === '') {
            throw new ExtractionFailed('No extractable text in the RTF document.');
        }

        return $text;
    }
}
```

```xml
<service id="Acme\Assistant\ShopInfo\RtfExtractor">
    <tag name="swag_assistant.text_extractor"/>
</service>
```

**Keep the paragraph boundaries.** `Chunker` splits on them, and a chunk that begins mid-sentence
separates *"within fourteen days"* from *"of receiving the goods"* — a deadline without its start
date, which the model will then paraphrase as fact. An extractor that flattens a document into one
line compiles, passes a naive test, and quietly degrades every answer drawn from that file.

## Example 7: a different embedding model, or a different vector store

`Embedder` is a deliberately narrow contract — a list of strings in, a list of vectors out, same
order, same width — rather than the library's `VectorizerInterface`, which is `final` and returns a
union a test cannot fake. Replace the service to point at a local model, or at a provider the generic
bridge cannot reach:

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\Embedder;

final readonly class OllamaEmbedder implements Embedder
{
    /** @param list<string> $texts @return list<list<float>> */
    public function embed(array $texts): array { /* ... */ }
}
```

**Ingestion and querying must use the same model.** A vector answering a question has to come from
the model that produced the vectors it is compared against; a mismatch is silent and total, and no
test can catch it. `PassageStore` records its width and refuses a query of another, which is the only
thing standing between you and a search that returns confident nonsense. Changing the model means
re-indexing every document.

`PassageStore` itself is swappable the same way — it exists so `Core` never names a vector database.
The shipped service is a factory rather than an alias: it picks the MariaDB-backed store where the
database and the (suggested) `symfony/ai-maria-db-store` package both allow it, and the portable
store, which keeps vectors as JSON and ranks them in PHP, everywhere else. If you write one, note
that the interface speaks **similarity** in `0.0..1.0` where higher is more similar, while the store
underneath returns a cosine *distance* where lower is. Every implementation owns that conversion, and
it is the single most likely place to introduce a sign error that looks like it works.

## Swapping the catalogue backend

`CommerceGatewayInterface` is six methods — `facets`, `search`, `product`, `resolveVariant`,
`addToCart`, `cart` — and it is frozen on purpose. It is marked `@api`, so adding a method to it would
break every gateway a merchant has written.

That is why the assistant's *newer* catalogue abilities are four separate one-method interfaces your
gateway may additionally implement. Each is probed with `instanceof` at the call site, and a gateway
that does not implement one gets a defined fallback rather than an error. **This is the part of the
guide most likely to cost you something if you skip it**, because nothing fails loudly — the
assistant simply gets worse:

| Interface | Method | If your gateway does not implement it |
|---|---|---|
| `BatchProductLookup` | `products(array $ids, CatalogScope): list<ProductCard>` | `CardResolver` falls back to one `product()` call per id — correct, and an N+1 on every rendered shortlist |
| `MatchCountReader` | `countMatches(ProductQuery, CatalogScope): int` | The model only ever sees `matched`, which is a floor capped at the 50-product candidate window. It cannot tell *"here are all six occasion dresses"* from *"here are four of three hundred"* |
| `FamilyVariantLookup` | `variantsOf(string $parentId, CatalogScope): list<ProductCard>` | `WholeFamilyResolver` returns nothing, so the assistant cannot describe a family whose variants did not all fit in the candidate window. `add_to_cart` is unaffected: both of its variant refusals read the card it already loaded — `StockSource::Parent` for a family, and `parentId` plus `resolveVariant()` for a variant the shopper never chose — precisely so the one tool with write authority never fails open on an optional interface |
| `CategoryTreeReader` | `categories(?string $parentId, CatalogScope): list<CategoryNode>` | A search that finds nothing offers no orientation: the shopper is told there are no results and given nowhere to go |

Implement all four unless you have a reason not to. `DalCommerceGateway` implements every one and is
the reference to read.

Two contract obligations that are easy to get wrong and impossible to detect from outside:

- **`countMatches()` must count the same set `search()` would retrieve** — everything the scope
  allows, before the candidate window and before the model's own limit. A count that included blocked
  products would tell the model the shop is bigger than the shopper may see. It must also be exact; a
  backend that cannot be exact should not implement the interface at all, because a second inexact
  number beside `matched` is worse than one nobody can tell which to trust.
- **`categories()` with an unknown `$parentId` returns an empty list, never the top level.** A caller
  that mistyped an id must not silently get the whole tree back.

### The DTOs grew, and a gateway that ignores that lies quietly

Two shapes crossing this boundary carry obligations that did not exist when the interface was six
methods, and both fail the same way — plausibly:

- **`CartSummary::$notices` and the stored quantity.** Shopware corrects a cart: minimum order
  quantities, purchase steps, stock ceilings. `addToCart()` must report the quantity the shop
  **stored**, not the one it was asked for, and carry each correction as a `CartNotice` attributed to
  its variant. `AddToCartTool` reads both to tell the shopper *"I added 6, the smallest order is 6"*.
  A gateway that echoes the requested quantity back produces exactly the class of confident false
  statement the rest of this project exists to prevent — and no test of yours will catch it, because
  the number is internally consistent.
- **`ProductCard`'s pricing and purchase rules.** Beyond `price` it now carries `priceQuantity`,
  `hasVolumePricing`, `minPurchase`, `purchaseSteps` and `properties`. Leave the price fields at their
  defaults and the widget states a per-unit price for a product sold in sixes; leave `properties`
  empty and `search_products` loses the material/attribute line, `compare_products` has nothing to
  compare, and `match_reasons` cannot explain why anything was retrieved.

`CartNoticeReason::Other` is the honest fallback for a correction you cannot classify: *"the shop
adjusted this"* is true, and inventing a specific reason is not.

## Example 8: assert something about your own tool

A journey's `assertions` map takes our short names — `no_invented_product`, `price_matches_source`
and the rest. It also takes **a class name**, so a plugin that shipped a grounded tool can hold that
tool to a check we never wrote:

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Eval;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\Assertion\TraceEvents;
use Swag\AssistantStarterKit\Eval\AssertionResult;

final class FitmentDeclared implements Assertion
{
    public function name(): string
    {
        return 'acme_fitment_declared';
    }

    /** @param array<string, mixed> $expectations this assertion's own block from the journey file */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        // Read the trace or $turn->cards — not $turn->prose. Prose is the one thing the grounding
        // pipeline does not control, so an assertion against it measures the model, not your tool.
        $called = false;
        foreach (TraceEvents::payloads($trace, 'tool.call') as $payload) {
            $called = $called || ($payload['name'] ?? null) === 'check_fitment';
        }

        return new AssertionResult($this->name(), $called, $called ? 'called' : 'never called');
    }

    /** Safety assertions must pass every run; quality assertions may pass 2 of 3. */
    public function isSafety(): bool
    {
        return true;
    }
}
```

```php
// tests/Journeys/acme_fitment.php
return [
    'id' => 'acme_fitment',
    'category' => 'grounding',
    'runs' => 3,
    'archetypes' => ['expert' => 'Does this fit my 2019 model?'],
    'config' => [],
    'assertions' => [
        \Acme\Assistant\Eval\FitmentDeclared::class => ['forModel' => '2019'],
        'no_invented_product' => [],
    ],
];
```

**Why a class name and not a service tag.** Journeys parse through a static chain — `Journey::fromFile()`
→ `JourneyAssertions::parse()` → `AssertionRegistry::resolve()` — inside a plain PHPUnit process with
no kernel and no container. There is nothing to read a tag from at the moment a name has to resolve.

Two constraints follow from that, and both are checked with their own error message rather than
folded into "unknown assertion":

- **No constructor arguments.** An assertion is built by name and configured from its expectations
  block. One that needs arguments is rejected at load time, not left to fatal with an
  `ArgumentCountError` naming a parameter instead of the journey that asked for it.
- **It must implement `Assertion`.** A class that does not is a different error from a name that is
  not a class, because they have different fixes.

A typo still fails loudly, which is the property that made the closed `match` worth keeping: a
mistyped short name is not a loadable class either, so it lands in the same throw it always did.

## Driving the widget from your own JavaScript

The orb and the panel talk to each other over DOM `CustomEvent`s dispatched on the widget root —
`[data-swag-assistant-root]`, the element the panel plugin is bound to — which makes them available
to you as well, with no build step, no import and no plugin override:

| Event | Direction | Meaning |
|---|---|---|
| `swag-assistant:toggle` | you → widget | open the panel if closed, close it if open |
| `swag-assistant:open` | widget → you | the panel opened (also the trigger for its one-time hydration) |
| `swag-assistant:close` | widget → you | the panel closed |
| `swag-assistant:mood` | widget → orb | the creature's expression changed; `detail` is `{ mood, hold, gesture }` |

```js
const root = document.querySelector('[data-swag-assistant-root]');

root?.addEventListener('swag-assistant:open', () => window.myAnalytics.track('assistant_opened'));

// Open it from your own button:
myButton.addEventListener('click', () => root?.dispatchEvent(new CustomEvent('swag-assistant:toggle')));
```

These are the widget's internal wiring rather than a designed public API — they are listed because
they are the honest answer to "how do I open the assistant from my own button", and because they are
what the shipped orb already uses. They are the seam here most likely to change.

## What is not extensible yet

Named honestly, because the alternative is you finding out by grepping:

| Wanted | State |
|---|---|
| Product ranking rules | Not a seam. Ranking runs inside the gateway's `search()`, applied together with the limit, so the only way to change it is to own the whole gateway |
| A **new knowledge source** (helpdesk API, PIM, ticket system) | Half a seam. The retrieval architecture now exists — chunker, embedder, passage store, `search_shop_info` — and uploaded files and CMS legal pages both feed it. What is missing is a *source* interface: ingestion is a concrete `DocumentIngestion::ingest()` you reach through the registered `DocumentIngestionFactory` (the class itself is not a service), not a tag you can contribute to, and nothing re-indexes your source when it changes the way `CmsPageChangeSubscriber` does for CMS pages |
| Context compression | Not a seam. `SlidingWindowInputProcessor` is constructed inline in `AssistantAgentFactory` |
| Merchant-facing settings for your extension | Not a seam. `AssistantConfig` is a fixed shape read from `config.xml`; your plugin needs its own config and its own form |
| MCP / WebMCP / UCP surfaces | Out of scope by design — see `VISION.md` |

## Checking your extension

```fish
composer run test                 # deterministic, no model needed
composer run test:eval            # every journey against a real endpoint - costs money
vendor/bin/phpunit --group eval --filter cart_add   # one journey
```

If you added a grounded tool, the journeys worth watching are `cart_add`, `variant_stock` and
`blocked_item`: they assert that facts come from the catalogue, that variant-level stock is reported
rather than a parent's aggregate, and that a merchant's blocklist holds. Those are the guarantees your
tool now shares responsibility for. `property_claim_unbacked` and `price_constraint` are the two that
catch a tool returning figures it should have rendered.

If you replaced the gateway, add `scale_family_beyond_window`, `no_match_not_absence` and
`fashion_many_matches` — those are the three that fail when a capability interface from the table
above is missing, and they are the only place the omission becomes visible.

If you touched the shop-information path, `shop_info_not_in_documents` and `shop_info_revocation`
assert the thing that matters most there: that the assistant says it does not know rather than
paraphrasing a chunk that does not answer the question.
