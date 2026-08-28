<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\Cart\MinOrderQuantityError;
use Shopware\Core\Content\Product\Cart\ProductNotFoundError;
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

    public function testAKnownOutOfStockErrorMapsToItsOwnReasonRatherThanOther(): void
    {
        // `product-out-of-stock` is one of the four keys CartNoticeReason::fromMessageKey() maps
        // explicitly, so ProductOutOfStockError must resolve to CartNoticeReason::OutOfStock, not
        // to the Other fallback. Previously named/commented as if this covered an UNKNOWN reason —
        // it does not; see testAnUnmappedMessageKeyBecomesOther() below for that case.
        $cart = $this->cart(0.0);
        $cart->addErrors(new ProductOutOfStockError(self::BLACK_M_ID, 'Trail Jersey'));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        self::assertSame(CartNoticeReason::OutOfStock, $summary->notices[0]?->reason);
    }

    public function testAnUnmappedMessageKeyBecomesOther(): void
    {
        // A genuinely unrecognised key — `product-not-found` is not one of the four
        // CartNoticeReason::fromMessageKey() maps explicitly — still means "the cart is not what
        // was asked for". Dropping it would let the tool report a clean add over a cart Shopware
        // complained about; `Other` is what CartCorrectionNote::because()'s default arm depends on.
        $cart = $this->cart(0.0);
        $cart->addErrors(new ProductNotFoundError(self::BLACK_M_ID));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        self::assertSame(CartNoticeReason::Other, $summary->notices[0]?->reason);
        self::assertSame(self::BLACK_M_ID, $summary->notices[0]?->variantId);
    }

    public function testAnErrorIdNotPrefixedByItsMessageKeyYieldsAnEmptyVariantIdRatherThanAWrongOne(): void
    {
        // notices() recovers the variant by stripping the message key off the front of the error's
        // id, on the assumption every cart error is built that way. GenericCartError is not: its id
        // and message key are independent, so an id that does not start with the key exercises the
        // branch every product-cart error happens to avoid — an unattributed notice is still true;
        // a misattributed one is not.
        $cart = $this->cart(0.0);
        $cart->addErrors(new GenericCartError(
            id: 'line-1',
            messageKey: 'min-order-quantity',
            parameters: [],
            level: Error::LEVEL_WARNING,
            blockOrder: true,
            persistent: true,
            blockResubmit: true,
        ));

        $summary = (new DalCartSummariser())->summarise($cart, 'EUR', '/checkout/confirm');

        self::assertCount(1, $summary->notices);
        self::assertSame('', $summary->notices[0]?->variantId);
        self::assertSame(CartNoticeReason::MinimumQuantity, $summary->notices[0]?->reason);
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
