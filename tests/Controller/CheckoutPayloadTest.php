<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Controller\CheckoutPayload;
use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The checkout link, built where the contact link is built and for the same reason.
 *
 * **Generated from the route, never written as a path.** The route name is Shopware-wide identical;
 * the URL is not — a sales-channel domain can carry a path prefix, so a literal `/checkout/confirm`
 * is wrong the moment a shop runs `example.com/de`. `DalCartAdapter` already generates it this way.
 *
 * And it is generated *here* rather than passed through the model, which is the whole of D3: the
 * model has never seen this URL, so it cannot retype it wrongly — the same argument
 * {@see \Swag\AssistantStarterKit\Controller\HandoffPayload} makes about the contact link.
 */
final class CheckoutPayloadTest extends TestCase
{
    public function testGeneratesTheShopsOwnCheckoutUrlForAnOfferedCheckout(): void
    {
        $payload = (new CheckoutPayload($this->router('/de/checkout/confirm')))->of(
            TurnOutcomeResolver::CHECKOUT_OFFERED,
            offered: true,
        );

        self::assertSame(['url' => '/de/checkout/confirm'], $payload);
    }

    public function testAnOfferBesideACartAddStillCarriesTheLink(): void
    {
        // The production defect: "add it and take me to checkout" is recorded `cart_added`, and
        // the link that keyed off the outcome alone never rendered beside a reply announcing it.
        $payload = new CheckoutPayload($this->router('/checkout/confirm'));

        self::assertSame(['url' => '/checkout/confirm'], $payload->of('cart_added', offered: true));
        self::assertNull($payload->of('cart_added', offered: false), 'an add alone did not ask to check out');
    }

    public function testATurnStoredBeforeTheOfferWasRecordedKeepsItsLink(): void
    {
        // Transcripts written before the flag existed carry only the outcome. `checkout_offered` is
        // proof of the offer on its own — the resolver records it only for a filled cart — so a
        // reload of one of those conversations must not lose its link.
        $payload = new CheckoutPayload($this->router('/checkout/confirm'));

        self::assertSame(
            ['url' => '/checkout/confirm'],
            $payload->of(TurnOutcomeResolver::CHECKOUT_OFFERED, offered: false),
        );
    }

    public function testIsNullForEveryOtherOutcomeEvenWhenCheckoutWasOffered(): void
    {
        // An escalation keeps the link away, and so does a turn that never finished: neither reply
        // is the one that told the shopper they can go to checkout.
        $payload = new CheckoutPayload($this->router('/checkout/confirm'));

        self::assertNull($payload->of('escalated', offered: true));
        self::assertNull($payload->of(TurnOutcomeResolver::TOOL_LIMIT_EXCEEDED, offered: true));
        self::assertNull($payload->of('error', offered: true));
        self::assertNull($payload->of('product_shown', offered: false));
        self::assertNull($payload->of('no_result', offered: false));
    }

    private function router(string $url): RouterInterface
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('generate')
            ->with('frontend.checkout.confirm.page', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn($url);

        return $router;
    }
}
