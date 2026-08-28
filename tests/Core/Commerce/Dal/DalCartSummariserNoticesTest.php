<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\Cart\MinOrderQuantityError;
use Shopware\Core\Content\Product\Cart\ProductOutOfStockError;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCartSummariser;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;

/**
 * Split out of {@see DalCartSummariserTest} (too-many-methods) rather than suppressed.
 *
 * Covers `CartSummary::$notices` — Shopware does not refuse a quantity it dislikes, it silently
 * changes it and records the reason as a cart error. Discarding that record is how "Added 10 to
 * the cart" gets said about a cart holding 8.
 */
final class DalCartSummariserNoticesTest extends TestCase
{
    private const BLACK_M_ID = 'a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5';

    private function price(float $unit, int $quantity): CalculatedPrice
    {
        return new CalculatedPrice(
            $unit,
            $unit * $quantity,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            $quantity,
        );
    }

    private function cart(float $cartTotal, LineItem ...$items): Cart
    {
        $cart = new Cart('test-token');
        foreach ($items as $item) {
            $cart->add($item);
        }

        $cart->setPrice(
            new CartPrice(
                $cartTotal,
                $cartTotal,
                $cartTotal,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                CartPrice::TAX_STATE_GROSS,
            ),
        );

        return $cart;
    }

    private function productLine(string $id, string $variantId, string $label, int $quantity, float $unit): LineItem
    {
        $item = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $variantId, $quantity);
        $item->setLabel($label);
        $item->setPrice($this->price($unit, $quantity));

        return $item;
    }

    public function testAQuantityShopwareCorrectedIsReportedAsANoticeAgainstItsVariant(): void
    {
        // Shopware raises an under-minimum line to minPurchase and records the reason instead of
        // refusing the add. Discarding that record is how "Added 10 to the cart" gets said about a
        // cart holding 24.
        $cart = $this->cart(1677.60, $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 24, 69.90));
        $cart->addErrors(new MinOrderQuantityError(self::BLACK_M_ID, 'Trail Jersey', 24));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        $notice = $summary->notices[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame(self::BLACK_M_ID, $notice->variantId);
        self::assertSame(CartNoticeReason::MinimumQuantity, $notice->reason);
    }

    public function testAnErrorThisPluginDoesNotKnowIsCarriedRatherThanDropped(): void
    {
        // An unknown reason still means "the cart is not what was asked for". Dropping it would
        // let the tool report a clean add over a cart Shopware complained about.
        $cart = $this->cart(0.0);
        $cart->addErrors(new ProductOutOfStockError(self::BLACK_M_ID, 'Trail Jersey'));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        self::assertSame(CartNoticeReason::OutOfStock, $summary->notices[0]?->reason);
    }

    public function testACleanCartCarriesNoNotices(): void
    {
        $summary = (new DalCartSummariser())->summarise(
            $this->cart(139.80, $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 2, 69.90)),
            'EUR',
            '/checkout/confirm',
        );

        self::assertSame([], $summary->notices);
    }
}
