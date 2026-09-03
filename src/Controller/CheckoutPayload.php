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
 * registration on its own. Null for every other outcome — an empty cart included, since
 * {@see TurnOutcomeResolver} only records this outcome when there was something to check out.
 */
final readonly class CheckoutPayload
{
    public function __construct(
        private RouterInterface $router,
    ) {}

    /**
     * @return array{url: string}|null
     */
    public function of(string $outcome): ?array
    {
        if ($outcome !== TurnOutcomeResolver::CHECKOUT_OFFERED) {
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
