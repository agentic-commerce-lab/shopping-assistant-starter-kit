<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderDocuments;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/** The order card's link is the account's order page, built from the route and never from model text. */
final class DalOrderDocumentsOrderPageTest extends TestCase
{
    public function testBuildsTheOrderPageFromTheRouteAndTheDeepLinkCode(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->expects(self::once())
            ->method('generate')
            ->with(
                'frontend.account.order.single.page',
                ['deepLinkCode' => 'abc'],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/account/order/abc');

        $order = new OrderEntity();
        $order->setDeepLinkCode('abc');

        self::assertSame('/account/order/abc', (new DalOrderDocuments($router))->orderPage($order));
    }

    public function testAnOrderWithoutADeepLinkCodeGetsNoLink(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('generate');

        self::assertNull((new DalOrderDocuments($router))->orderPage(new OrderEntity()));
    }
}
