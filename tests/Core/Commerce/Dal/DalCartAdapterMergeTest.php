<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\ProductLineItemFactory;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\PriceDefinitionFactory;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCartAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RouterInterface;

/**
 * Adding a variant that is already in the cart must raise that line, not open a second one.
 *
 * Shopware merges a line only when the incoming line's id equals an existing one
 * (`LineItemCollection::add()`), and `LineItemFactoryRegistry::create()` invents a random id when
 * none is given. So the factory and the merge are the real Shopware classes here; only
 * `CartService`'s persistence is stood in for, by a double that does exactly what the real service
 * does with the line — `Cart::add()` — and nothing else.
 */
final class DalCartAdapterMergeTest extends TestCase
{
    private const GRAVEL_L = 'c59711ff493fb0702a6df759760bf6a7';

    public function testTheSameVariantAddedTwiceIsOneLine(): void
    {
        $cart = new Cart('test-token');
        $adapter = $this->adapter($cart);

        $adapter->add(self::GRAVEL_L, 1, $this->context());
        $summary = $adapter->add(self::GRAVEL_L, 1, $this->context());

        self::assertCount(1, $summary->lineItems);
        self::assertSame(2, $summary->lineItems[0]->quantity ?? null);
    }

    public function testTheLineMergesWithOneTheStorefrontButtonAdded(): void
    {
        // The widget's own button posts Shopware's buy-widget shape, `lineItems[<productId>][id]`,
        // so a unit added by a click sits on a line whose id is the variant id.
        // It also posts `stackable=1`, as Shopware's product factory sets for every product line.
        $clicked = new LineItem(self::GRAVEL_L, LineItem::PRODUCT_LINE_ITEM_TYPE, self::GRAVEL_L, 1);
        $clicked->setStackable(true);
        $cart = new Cart('test-token');
        $cart->add($clicked);

        $summary = $this->adapter($cart)->add(self::GRAVEL_L, 2, $this->context());

        self::assertCount(1, $summary->lineItems);
        self::assertSame(3, $summary->lineItems[0]->quantity ?? null);
    }

    private function adapter(Cart $cart): DalCartAdapter
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($cart);
        $cartService
            ->method('add')
            ->willReturnCallback(static function (Cart $into, LineItem $item): Cart {
                $into->add($item);

                return $into;
            });

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/checkout/confirm');

        return new DalCartAdapter(
            $cartService,
            new LineItemFactoryRegistry(
                [new ProductLineItemFactory(new PriceDefinitionFactory())],
                // Shopware's line-item schema includes an entity-exists check that needs the DAL.
                // Validation is not what this test is about; the id and the merge are.
                $this->createStub(DataValidator::class),
                new EventDispatcher(),
            ),
            $router,
        );
    }

    private function context(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn('test-token');
        $context->method('getCurrency')->willReturn($currency);

        return $context;
    }
}
