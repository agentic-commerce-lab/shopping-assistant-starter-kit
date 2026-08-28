<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * Split out of {@see FixtureCommerceGatewayTest} (too-many-methods) rather than suppressed,
 * mirroring the house pattern in {@see \Swag\AssistantStarterKit\Tests\Core\Commerce\Dal\DalProductCardMapperAdvancedPriceTest}.
 *
 * Not decoration: the fixture gateway used to store whatever it was handed, so every eval and
 * every fixture test agreed with a tool that was misreporting quantities. A fake that cannot
 * reproduce Shopware's own quantity correction cannot protect against that defect.
 */
final class FixtureCommerceGatewayQuantityCorrectionTest extends TestCase
{
    public function testTheFixtureCorrectsAQuantityTheSameWayShopwareDoes(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        $cart = $gateway->addToCart('fx-021', 10);

        self::assertSame(8, $cart->lineItems[0]?->quantity);
        self::assertSame(CartNoticeReason::PurchaseSteps, $cart->notices[0]?->reason);
        self::assertSame('fx-021', $cart->notices[0]?->variantId);
    }
}
