# Extending the assistant

Four seams, each with a working example you can copy. All of them are ordinary Symfony service
wiring — nothing here needs a fork of this plugin.

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
| Change the widget's markup | Twig blocks | template override, see the README |
| Swap the catalogue backend entirely | `CommerceGatewayInterface` | decorate the service |

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
- **`GroundedToolFactoryInterface`** receives a `GroundedToolContext`: the gateway, the fact renderer,
  the blocklist, the variant resolver. Use it when your tool genuinely answers from the catalogue, and
  render shopper-facing facts through `FactRenderer` rather than returning them yourself.

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
        if (!$context->config->enableAddToCart) {
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

## Example 2: a tool that answers from the catalogue

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Tool;

use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;

final readonly class CompatibilityToolFactory implements GroundedToolFactoryInterface
{
    public function create(GroundedToolContext $context): ?object
    {
        return new CompatibilityTool(
            $context->gateway,
            // Facts a shopper will read go through the renderer. Returning a raw price from your own
            // tool is the one thing this tier lets you do and should not.
            $context->renderer,
            $context->blocklist,
            $context->trace,
            $context->config,
        );
    }
}
```

```xml
<service id="Acme\Assistant\Tool\CompatibilityToolFactory">
    <tag name="swag_assistant.grounded_tool_factory"/>
</service>
```

Two obligations come with this tier, and neither is enforced mechanically:

1. **Render facts, do not return them.** `$context->renderer->render($cards)` produces the cards the
   response carries; hand the model ids and let the server supply the numbers.
2. **Apply the blocklist.** `$context->blocklist->apply($cards, $context->config->scope)` — a merchant
   who excluded a product excluded it from your tool too, and a blocklist that a plugin can bypass
   does not survive a compliance review.

## Example 3: change the system prompt

```php
<?php declare(strict_types=1);

namespace Acme\Assistant\Prompt;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;

final readonly class AcmePromptProvider implements PromptProviderInterface
{
    public function __construct(private PromptProviderInterface $inner) {}

    public function system(AssistantConfig $config, string $vocabulary = ''): string
    {
        // Appending keeps the shipped rules. Replacing drops them - see below.
        return $this->inner->system($config, $vocabulary)
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

**Decorate rather than replace unless you mean it.** The shipped prompt carries the injection
defence, the rule against claiming what the shop does not sell, and the escalation clause — and each
of those has an eval journey behind it. A wholesale replacement will fail those journeys, which is the
intended way to discover you dropped one. Run `composer run test:eval` after changing the prompt.

## Example 4: send turns to your analytics

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

## What is not extensible yet

Named honestly, because the alternative is you finding out by grepping:

| Wanted | State |
|---|---|
| Product ranking rules | Not a seam. Ranking runs inside the gateway's `search()`, applied together with the limit |
| Knowledge sources (FAQ, manuals, CMS) | Not a seam, and not a small one — there is no retrieval architecture to hang it on. A grounded tool is the workaround |
| Context compression | `SlidingWindowInputProcessor` is constructed inline in `AssistantAgentFactory` |
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
tool now shares responsibility for.
