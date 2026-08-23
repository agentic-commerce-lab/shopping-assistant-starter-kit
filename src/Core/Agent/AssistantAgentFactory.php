<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Llm\LlmPlatformInterface;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\SymfonyAiPlatform;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;
use Swag\AssistantStarterKit\Core\Prompt\SystemPromptProvider;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\Factory\AddToCartToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\EscalateToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\GetProductToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Tool\Factory\SearchProductsToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds everything one request's turn needs, from scratch, every time.
 *
 * "Fresh per request" is not an efficiency choice, it is Ruling R32: exactly
 * ONE {@see \Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface}
 * is built here and handed to every tool and pipeline service that needs one.
 * {@see AddToCartTool} reads `gateway->cart()->total` live to enforce
 * `maxCartValue`; a gateway built per tool instead of per request would let
 * every `add_to_cart` call see an empty cart, and a model could drip-feed past
 * the limit one item at a time — exactly how a model would do it.
 *
 * Capability control lives here, not in a prompt instruction: {@see AddToCartTool}
 * is simply never constructed, and therefore never appears in the
 * {@see Toolbox} the model sees, when `enableAddToCart` is off or no shopper
 * cart exists.
 *
 * Ruling R34: the gateway is a required parameter, not a path this factory
 * resolves itself. A production `src/` default that pointed at a fixture
 * under `tests/` (this class's first shape) is at best dead and at worst
 * surprising once this factory ships into a real Shopware installation. The
 * caller already knows its own catalog source: Plan 1's tests build a
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway} from
 * a fixture file and pass it in; Plan 2's caller builds a DAL-backed gateway
 * and passes that instead. This factory does not need to know how a gateway
 * comes to exist, only that exactly one instance backs the whole request
 * (Ruling R32).
 */
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
     * {@see \Swag\AssistantStarterKit\Eval\JourneyAttempt}, the probe command and the unit tests all
     * build this pipeline without Symfony, and that is deliberate rather than incidental: the eval
     * suite's selling point is that it needs no Shopware and no database and runs in seconds. This
     * keeps that true, while the storefront gets the container's tagged factories instead.
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
     * The same pipeline plus contributed factories, for tests that need to act like a shop with a
     * third-party tool installed.
     *
     * @param list<ToolFactoryInterface>         $toolFactories
     * @param list<GroundedToolFactoryInterface> $groundedToolFactories
     */
    public function withAdditionalFactories(array $toolFactories, array $groundedToolFactories): self
    {
        return new self(
            [...self::listOf($this->toolFactories), ...$toolFactories],
            [...self::listOf($this->groundedToolFactories), ...$groundedToolFactories],
            $this->prompt,
            $this->platform,
        );
    }

    /**
     * A tagged iterator is a `Traversable` with string keys, and neither spreads. Normalised here so
     * {@see self::withAdditionalFactories()} can concatenate container-provided factories with
     * test-provided ones without caring which it was handed.
     *
     * @template T of object
     *
     * @param iterable<T> $factories
     *
     * @return list<T>
     */
    private static function listOf(iterable $factories): array
    {
        return \is_array($factories) ? array_values($factories) : iterator_to_array($factories, false);
    }

    public function create(
        CommerceGatewayInterface $gateway,
        AssistantConfig $config,
        bool $cartAvailable,
        LlmSettings $llm,
    ): Bundle {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $facetProbe = new FacetProbe($gateway, $trace);

        // Probed here, once, before we know whether the model will call a tool at all —
        // FacetProbe caches per scope for the life of this request, so SearchProductsTool's
        // own probe() call below (same $facetProbe instance, same $config->scope) reuses
        // this result instead of hitting the gateway again. A turn that never calls a tool
        // still pays this one probe; that is the accepted cost of having the vocabulary
        // available before the system prompt is built.
        $vocabularyStats = CatalogVocabulary::renderWithStats($facetProbe->probe($config->scope));
        $trace->record('vocabulary.render', [
            'fieldCount' => $vocabularyStats['fieldCount'],
            'valueCount' => $vocabularyStats['valueCount'],
            'truncated' => $vocabularyStats['truncated'],
        ]);

        $queryBuilder = new QueryBuilder();
        $variantResolver = new VariantResolver($gateway, $trace);
        $blocklist = new BlocklistFilter();

        // Grounded factories first, so the shipped tool order — search, get, cart, then escalate — is
        // exactly what it was before this became a service. A reordered toolbox changes which tool a
        // model reaches for first, and that is not a change to make accidentally inside a refactor.
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
        $context = new ToolContext($trace, $config);

        $tools = [];

        foreach ($this->groundedToolFactories as $factory) {
            $tools[] = $factory->create($groundedContext);
        }

        foreach ($this->toolFactories as $factory) {
            $tools[] = $factory->create($context);
        }

        // A factory returning null contributed nothing: that is how enableAddToCart and
        // enableEscalation are enforced, and array_filter is where "never constructed" becomes
        // "never in the toolbox the model sees".
        $tools = array_values(array_filter($tools));

        // AgentProcessor's own maxToolCalls argument is provably inert — see
        // BoundedToolbox's docblock — so it is not what enforces the bound. It is
        // still passed through for whatever single-round protection it happens to
        // offer; BoundedToolbox is the real, request-wide counter.
        $toolbox = new BoundedToolbox(new Toolbox($tools), $config->maxToolCallsPerTurn, $trace);

        $toolProcessor = new AgentProcessor($toolbox, maxToolCalls: $config->maxToolCallsPerTurn);

        // AgentProcessor drives the tool loop, so GroundingOutputProcessor is kept after it
        // here — this is the order that would be load-bearing if AgentProcessor stopped
        // recursively re-invoking Agent::call() per tool round. Per Ruling R33, the installed
        // 0.12 AgentProcessor already resolves every tool call before any processor ever sees
        // a real TextResult, in either order — see OutputProcessorOrderTest, which is kept as
        // the regression check for that finding, not as proof this order is currently required.
        $agent = new Agent(
            $this->platform->of($llm),
            $llm->model,
            inputProcessors: [new SlidingWindowInputProcessor(), $toolProcessor],
            outputProcessors: [$toolProcessor, new GroundingOutputProcessor($renderer, $trace)],
        );

        return new Bundle($agent, $renderer, $trace, $toolbox, $this->prompt, $vocabularyStats['text']);
    }
}
