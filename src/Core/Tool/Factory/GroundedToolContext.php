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
 * That is ruling R32, and it is load-bearing rather than tidy:
 * {@see \Swag\AssistantStarterKit\Core\Tool\AddToCartTool} reads `gateway->cart()->total` live to
 * enforce `maxCartValue`, so a tool holding its own gateway would see an empty cart on every call and
 * a model could drip-feed past the limit one item at a time — exactly how a model would do it. One
 * instance per request, built once by
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
        /**
         * The category the shopper is browsing, when the storefront reported one.
         *
         * **A tool may use this only to narrow.** It is client-supplied and never resolved against
         * the catalogue, so it grants nothing: as a `ProductQuery` constraint it is AND-ed with the
         * merchant's scope and can only ever return fewer products (P8). Putting it anywhere the
         * scope is OR-ed would turn it into a policy bypass.
         */
        public ?string $browsingCategoryId = null,
        /**
         * The shopper's own sentence for this turn.
         *
         * Client-claimed per-turn state, like `browsingCategoryId` above and for the same reason —
         * see `ShopwareChatTurnRunner::run()` on why that provenance is never merged with what the
         * server knows. It is here so the SHOP can read what the shopper asked for instead of relying
         * on the model to pass it: {@see \Swag\AssistantStarterKit\Core\Retrieval\SuperlativeSort}
         * turns "the cheapest coat" into an ordering, after a model named a coat that was not the
         * cheapest one on screen three samples running.
         *
         * A tool may read it to decide HOW to search. It is not a source of facts: nothing a shopper
         * types backs a price, a stock level or a product's existence.
         */
        public string $shopperMessage = '',
    ) {}
}
