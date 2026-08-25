<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Swag\AssistantStarterKit\Core\Commerce\CardResolver;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Re-renders the cards a stored conversation referred to.
 *
 * ## Why this exists
 *
 * `GET /assistant/history` returns `cardIds` and no card data, deliberately: *"replaying stored
 * figures would show numbers that were true when written — the UI asks again if it wants them."*
 * Until this route existed there was nothing to ask. The visible consequence was a re-hydrated
 * conversation in which the assistant said *"the card here shows its current price and stock"* with
 * no card beneath it — prose referring to something that was not there.
 *
 * ## Why re-rendering rather than replaying
 *
 * Every card is rendered from the catalogue **now**, so a price or stock level that changed since the
 * conversation shows its current value. That is the entire point: a shopper returning to a tab an
 * hour later must not be quoted an hour-old figure (D4).
 *
 * Two consequences follow from using the merchant's own `CatalogScope`, and both are intended:
 * a product blocked or excluded **since** that turn does not come back, and a product that no longer
 * exists is omitted rather than erroring. The response is therefore allowed to be shorter than the
 * request — a client must render what it receives, not assume a one-to-one mapping.
 *
 * Separate from {@see AssistantController} because that class is already at four collaborators and
 * this is a different question — not *"what did the assistant say"* but *"what is true about these
 * products right now"*.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class AssistantCardController extends StorefrontController
{
    public function __construct(
        private readonly CardResolver $cards,
        private readonly SystemConfigAssistantConfig $assistantConfig,
        private readonly CardPayload $cardPayload = new CardPayload(),
    ) {}

    #[Route(
        path: '/assistant/cards',
        name: 'frontend.assistant.cards',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function cards(Request $request, SalesChannelContext $context): Response
    {
        $ids = CardIdList::fromRequest($request);

        if ($ids === []) {
            // Not an error: a conversation whose turns rendered nothing has nothing to resolve.
            return new JsonResponse(['cards' => []]);
        }

        $scope = $this->assistantConfig->forSalesChannel($context->getSalesChannelId())->scope;

        // One round trip where the gateway supports it, a loop where it does not. Phase B measured
        // the loop at 12 lookups and 143.8 ms for a full row, which is why `CardIdList::MAX_IDS`
        // is 12 — see CardResolver.
        $cards = $this->cards->resolve($ids, $scope);

        return new JsonResponse(['cards' => $this->cardPayload->of($cards)]);
    }
}
