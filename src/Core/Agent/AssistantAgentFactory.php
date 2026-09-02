<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;
use Swag\AssistantStarterKit\Core\Grounding\DisclosedOptions;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Llm\LlmPlatformInterface;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\SymfonyAiPlatform;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;
use Swag\AssistantStarterKit\Core\Prompt\RecentCardsContext;
use Swag\AssistantStarterKit\Core\Prompt\SystemPromptProvider;
use Swag\AssistantStarterKit\Core\Prompt\ViewingContext;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\SharedFacetCache;
use Swag\AssistantStarterKit\Core\Tool\Factory\AddToCartToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\CompareProductsToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\EscalateToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\GetProductToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Tool\Factory\SearchProductsToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Swag\AssistantStarterKit\Core\Tool\FamilyOptionValues;
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
     * The stage under which {@see self::create()} records the model that will answer the turn.
     *
     * A constant rather than a literal because three readers agree on the string and none of them
     * can see the others: `phases.js` files it under the "Prepared" phase, `facts.js` prints it on
     * the timeline, and `TraceExportSummary` lifts it into the exported summary. A typo here would
     * leave all three quietly showing nothing.
     */
    public const MODEL_STAGE = 'model';

    /**
     * @param iterable<ToolFactoryInterface>         $toolFactories
     * @param iterable<GroundedToolFactoryInterface> $groundedToolFactories
     */
    public function __construct(
        private iterable $toolFactories,
        private iterable $groundedToolFactories,
        private PromptProviderInterface $prompt,
        private LlmPlatformInterface $platform,
        /**
         * The cross-request facet cache, or null to probe live every request.
         *
         * Nullable because the eval suite builds this factory by hand with no container, and a
         * measurement-driven cache must not become a construction requirement. In the shop it is
         * always wired — see services.xml, and phase B's Finding 1 for the 560 ms it saves.
         */
        private ?SharedFacetCache $facetCache = null,
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
            [
                new SearchProductsToolFactory(),
                new GetProductToolFactory(),
                new AddToCartToolFactory(),
                new CompareProductsToolFactory(),
            ],
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

    /**
     * Three of these are the turn's world — gateway, config, LLM — and the rest are what this
     * particular request happens to be: whether a cart exists, what the shopper has open, where they
     * are standing, and what they were shown a moment ago. They are already grouped everywhere it
     * helps (AssistantConfig, GroundedToolContext); one more level would only move the list
     * somewhere a caller cannot see it, and every parameter past the fourth is optional.
     *
     * @param list<ProductCard> $recentCards the cards the previous assistant reply rendered — see
     *                                       {@see RecentCardsContext} for the wrong-variant-in-cart
     *                                       defect this closes
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function create(
        CommerceGatewayInterface $gateway,
        AssistantConfig $config,
        bool $cartAvailable,
        LlmSettings $llm,
        ?ProductCard $viewing = null,
        ?string $browsingCategoryId = null,
        array $recentCards = [],
    ): Bundle {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        // **Which model answered, recorded first and every turn.** Every other stage of the turn was
        // already traceable; the one thing a merchant reading a bad reply could not tell was whether
        // the shop had been pointed at a different model that week. `MODEL_STAGE` is recorded before
        // anything can fail, so it is present even on a turn that dies inside the agent — which is
        // precisely the turn someone opens the trace to explain.
        //
        // It rides in the trace rather than in a column on the conversation, deliberately: the model
        // is a per-sales-channel setting and can change between two turns of the same conversation,
        // so a column could only ever record one of them and would be wrong about the other. The
        // export's summary reports the distinct names it finds — see
        // {@see \Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSummary}.
        //
        // The name only. `LlmSettings::$baseUrl` is not recorded here: an exported trace is a file a
        // merchant forwards, and a provider URL is the one part of that config that has been seen
        // carrying a credential in its path.
        $trace->record(self::MODEL_STAGE, ['name' => $llm->model]);

        // Pre-grounding, and the reason this feature removes a model round trip.
        //
        // `registerRetrieved()` does two different things, both of which this relies on: the
        // retrieved *index* accumulates, so the open product stays nameable for the whole turn
        // without `validate()` counting it as invented even after a search runs; while
        // `lastBatchIds` is *replaced*, so it is the default rendered card set only until a tool
        // returns something newer. A turn that calls no tool therefore renders the product the
        // shopper is already looking at, with its real price and stock, and the model never had to
        // ask for it.
        // **Order matters, and it is the only subtle thing here.** `registerRetrieved()` accumulates
        // the retrieved *index* — which is what keeps both sets nameable without `validate()`
        // counting them as invented — but REPLACES `lastBatchIds`, the default card set for a turn
        // that calls no tool. The product on screen is the more specific answer to "what is this
        // about" than a shortlist from the previous reply, so it is registered last and wins.
        if ($recentCards !== []) {
            $renderer->registerRetrieved($recentCards);
        }

        if ($viewing !== null) {
            $renderer->registerRetrieved([$viewing]);
        }

        $facetProbe = new FacetProbe($gateway, $trace, $this->facetCache);

        // Probed here, once, before we know whether the model will call a tool at all —
        // FacetProbe caches per scope for the life of this request, so SearchProductsTool's
        // own probe() call below (same $facetProbe instance, same $config->scope) reuses
        // this result instead of hitting the gateway again. A turn that never calls a tool
        // still pays this one probe; that is the accepted cost of having the vocabulary
        // available before the system prompt is built.
        //
        // With $facetCache wired, "this one probe" is a cache read rather than a ~560 ms
        // aggregation over the whole catalogue — which is what made that accepted cost
        // acceptable in the first place. Phase B measured it at 558–578 ms on 10k products.
        $facets = $facetProbe->probe($config->scope);
        $vocabularyStats = CatalogVocabulary::renderWithStats($facets);
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
            browsingCategoryId: $browsingCategoryId,
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
        // WholeNumberToolArguments, not the framework's default resolver: a model states a
        // budget as a JSON integer, and the default rejects it against a `float` parameter
        // before the tool is entered. See that class for the measurement.
        $toolbox = new BoundedToolbox(
            new Toolbox($tools, argumentResolver: new WholeNumberToolArguments()),
            $config->maxToolCallsPerTurn,
            $trace,
        );

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
            outputProcessors: [$toolProcessor, new GroundingOutputProcessor($renderer, $trace, $facets)],
        );

        $familyOptions = self::familyOptionsOf($gateway, $viewing, $config->scope);

        // Recorded because the prompt is about to state these values to the model, and the property
        // audit measures a reply against what the shop gave it. Without this line the one question
        // `familyOptionsOf()` exists to answer — "which sizes are available?" — came back correct
        // and annotated as suspect, because no retrieved card carries a sibling's size. See
        // {@see DisclosedOptions}, and note the same recording happens for `search_products`'
        // `families` block, which discloses option values for the same reason.
        if ($familyOptions !== []) {
            $trace->record(DisclosedOptions::STAGE, [
                'source' => 'viewing',
                'options' => DisclosedOptions::valuesOf([$familyOptions]),
            ]);
        }

        return new Bundle(
            $agent,
            $renderer,
            $trace,
            $toolbox,
            $this->prompt,
            $vocabularyStats['text'],
            self::promptContext($viewing, $familyOptions, $recentCards),
        );
    }

    /**
     * Every option value the viewed product's family offers, or `[]` when there is no family.
     *
     * **Why the prompt needs this and the pre-grounded card does not.** A detail page reports the
     * *variant* the shopper selected — the storefront template says so outright, and it is the right
     * id for "is this in stock?" and for add-to-cart, both of which must mean that exact unit. But
     * {@see ViewingContext} then tells the model not to call a tool to look this product up, and a
     * model obeying both facts answers *"which sizes are available?"* from the single size it was
     * given. Measured on the staging shop 2026-09-02: *"specifically available in size L. There are
     * no other sizes currently listed for this product."* — correctly grounded, and wrong.
     *
     * Making the model search instead would have undone the round trip this feature exists to save
     * (10.1 s, see `ViewingContext`'s own docblock). Naming the family's option values costs one
     * gateway query and keeps both.
     *
     * `instanceof` rather than a wider `CommerceGatewayInterface`, matching
     * {@see \Swag\AssistantStarterKit\Core\Tool\WholeFamilyResolver}: family lookup is an optional
     * capability, and a gateway without it degrades to the line it printed before.
     *
     * Through `$scope`, so a blocked sibling is never named. Without it the blocklist would leak the
     * exact catalogue it exists to hide into the system prompt, where nothing downstream filters it.
     *
     * @return array<string, list<string>>
     */
    private static function familyOptionsOf(
        CommerceGatewayInterface $gateway,
        ?ProductCard $viewing,
        CatalogScope $scope,
    ): array {
        $parentId = $viewing?->parentId;

        if ($parentId === null || !$gateway instanceof FamilyVariantLookup) {
            return [];
        }

        return FamilyOptionValues::of($gateway->variantsOf($parentId, $scope))['options'];
    }

    /**
     * The two "you already know about these" clauses, as one block for {@see SystemPrompt::build()}.
     *
     * Concatenated rather than given their own prompt parameter: `build()` appends this string after
     * the rules and the vocabulary, and both clauses belong in exactly that position. A second
     * parameter would have to be threaded through `Bundle` and every caller to say the same thing.
     *
     * Either half may be empty — most turns have no page product, and the first turn of a
     * conversation has no previous reply — so the blank line between them is only written when both
     * are actually present.
     *
     * @param array<string, list<string>> $familyOptions
     * @param list<ProductCard>           $recentCards
     */
    private static function promptContext(?ProductCard $viewing, array $familyOptions, array $recentCards): string
    {
        $clauses = array_filter(
            [
                ViewingContext::line($viewing, $familyOptions),
                RecentCardsContext::line($recentCards),
            ],
            static fn(string $clause): bool => $clause !== '',
        );

        return implode("\n\n", $clauses);
    }
}
