<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The checkout link that goes out beside a reply where the shopper asked to check out.
 *
 * Built here for the reason {@see HandoffPayload} is: the model supplies words, the shop supplies
 * facts, and a URL is a fact (D3). The model has never seen this address, so it cannot have got it
 * wrong — and until this existed the only checkout URL in the system was the one `AddToCartTool`
 * handed the model while the rules forbade it to write one. A live shop replied "here is a link to
 * the checkout" with no link, which is the only output that satisfies both.
 *
 * **Generated from the route, never written as a path.** The route name is the same in every
 * Shopware installation; the URL is not. A sales-channel domain may carry a path prefix, so a
 * literal `/checkout/confirm` is wrong the moment a shop serves `example.com/de` — which is why
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCartAdapter} already generates it this way.
 *
 * `confirm`, not `cart`: the shopper asked to check out, and core sends a guest on from there to
 * registration on its own.
 */
final readonly class CheckoutPayload
{
    /**
     * The one outcome that outranks `checkout_offered` without cancelling it — see {@see self::of()}.
     */
    public function __construct(
        private RouterInterface $router,
    ) {}

    /**
     * Two inputs, because an outcome is one value and a turn can do two things.
     *
     * **Found in production traces:** "add it and take me to checkout" ran both tools, the model was
     * told a link followed, and none did — the turn is `cart_added`, and this used to read the
     * outcome alone. Re-ranking was not the fix: the widget refreshes the header cart only on
     * `cart_added`, and the cart funnel counts from it. So the offer travels beside the outcome
     * (`$offered`, read from the trace by {@see \Swag\AssistantStarterKit\Core\Agent\CheckoutOffer})
     * and matters where the outcome cannot speak for it.
     *
     * `checkout_offered` needs no flag: {@see TurnOutcomeResolver} records it only for a filled cart,
     * which is also what keeps a transcript stored before the flag existed rendering its link on
     * reload. Every other outcome keeps the link away even when checkout was offered — an escalation
     * is a turn that reached for a human, and a turn cut short never gave the reply that said the
     * shopper can check out. An empty cart is never an offer, so it never renders one either.
     *
     * @return array{url: string}|null
     */
    public function of(string $outcome, bool $offered): ?array
    {
        $besideAnAdd = $offered && $outcome === TurnOutcomeResolver::CART_ADDED;

        if ($outcome !== TurnOutcomeResolver::CHECKOUT_OFFERED && !$besideAnAdd) {
            return null;
        }

        return [
            // A path rather than an absolute URL: the widget runs on the shop's own pages, and an
            // absolute address would pin the link to whichever domain generated it.
            'url' => $this->router->generate(
                'frontend.checkout.confirm.page',
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            ),
        ];
    }
}
