<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\PlatformFactory;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Tool\GetProductTool;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
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
 */
final class AssistantAgentFactory
{
    /**
     * Plan 1 ships no Shopware DAL yet, so this points at the same fixture
     * catalog every other task's tests already use. Plan 2's DAL-backed
     * gateway removes this default entirely — see the class docblock and the
     * task report for why a production `src/` default currently points at a
     * fixture under `tests/`.
     */
    private const DEFAULT_CATALOG_PATH = __DIR__ . '/../../../tests/Fixtures/catalog.json';

    public static function create(
        AssistantConfig $config,
        bool $cartAvailable,
        LlmSettings $llm,
        ?HttpClientInterface $http = null,
        string $catalogPath = self::DEFAULT_CATALOG_PATH,
    ): Bundle {
        $gateway = FixtureCommerceGateway::fromFile($catalogPath);
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $facetProbe = new FacetProbe($gateway, $trace);
        $queryBuilder = new QueryBuilder();
        $variantResolver = new VariantResolver($gateway, $trace);
        $blocklist = new BlocklistFilter();

        $tools = [
            new SearchProductsTool(
                $gateway,
                $facetProbe,
                $queryBuilder,
                $variantResolver,
                $blocklist,
                $renderer,
                $trace,
                $config,
            ),
            new GetProductTool($gateway, $variantResolver, $blocklist, $renderer, $trace, $config),
            new EscalateTool($trace),
        ];

        // An unavailable tool is never constructed, so the model never sees it in the
        // toolbox's schema — that is what keeps capability control out of the prompt.
        if ($config->enableAddToCart && $cartAvailable) {
            $tools[] = new AddToCartTool($gateway, $trace, $config);
        }

        $toolbox = new Toolbox($tools);

        $toolProcessor = new AgentProcessor($toolbox, maxToolCalls: $config->maxToolCallsPerTurn);

        // Order is load-bearing per the task brief: AgentProcessor drives the tool loop, so
        // GroundingOutputProcessor must come after it here. See GroundingOutputProcessorOrderTest
        // and the task report for what was actually verified about the installed 0.12
        // AgentProcessor's behaviour when this order is swapped.
        $agent = new Agent(
            PlatformFactory::create($llm, $http),
            $llm->model,
            inputProcessors: [new SlidingWindowInputProcessor(), $toolProcessor],
            outputProcessors: [$toolProcessor, new GroundingOutputProcessor($renderer, $trace)],
        );

        return new Bundle($agent, $renderer, $trace, $toolbox);
    }
}
