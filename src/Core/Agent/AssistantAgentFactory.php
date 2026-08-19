<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory\Bundle;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
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
final class AssistantAgentFactory
{
    public static function create(
        CommerceGatewayInterface $gateway,
        AssistantConfig $config,
        bool $cartAvailable,
        LlmSettings $llm,
        ?HttpClientInterface $http = null,
    ): Bundle {
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

        // AgentProcessor drives the tool loop, so GroundingOutputProcessor is kept after it
        // here — this is the order that would be load-bearing if AgentProcessor stopped
        // recursively re-invoking Agent::call() per tool round. Per Ruling R33, the installed
        // 0.12 AgentProcessor already resolves every tool call before any processor ever sees
        // a real TextResult, in either order — see OutputProcessorOrderTest, which is kept as
        // the regression check for that finding, not as proof this order is currently required.
        $agent = new Agent(
            PlatformFactory::create($llm, $http),
            $llm->model,
            inputProcessors: [new SlidingWindowInputProcessor(), $toolProcessor],
            outputProcessors: [$toolProcessor, new GroundingOutputProcessor($renderer, $trace)],
        );

        return new Bundle($agent, $renderer, $trace, $toolbox);
    }
}
