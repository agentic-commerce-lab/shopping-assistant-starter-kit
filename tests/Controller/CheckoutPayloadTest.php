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
        $payload = (new CheckoutPayload($this->router(
            '/de/checkout/confirm',
        )))->of(TurnOutcomeResolver::CHECKOUT_OFFERED);

        self::assertSame(['url' => '/de/checkout/confirm'], $payload);
    }

    public function testIsNullForEveryOtherOutcome(): void
    {
        $payload = new CheckoutPayload($this->router('/checkout/confirm'));

        self::assertNull($payload->of('product_shown'));
        self::assertNull($payload->of('escalated'));
        self::assertNull($payload->of('no_result'));
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
