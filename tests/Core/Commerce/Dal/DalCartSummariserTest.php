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
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCartSummariser;

/**
 * Built from real `Cart` and `LineItem` objects, no mocks: this is the class whose mistakes show a
 * shopper a number that disagrees with the cart page, and the assertions should be trustworthy
 * without a reader having to audit four test doubles first.
 */
final class DalCartSummariserTest extends TestCase
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

    public function testTheAddedVariantAndItsOwnPriceAreReported(): void
    {
        // Black/M costs 69.90 in the seeded catalogue, five euros below the parent's 79.90.
        $summary = (new DalCartSummariser())->summarise(
            $this->cart(139.80, $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 2, 69.90)),
            'EUR',
            '/checkout/confirm',
        );

        self::assertCount(1, $summary->lineItems);

        $line = $summary->lineItems[0] ?? null;
        self::assertNotNull($line);
        self::assertSame(self::BLACK_M_ID, $line->variantId);
        self::assertSame(69.90, $line->unitPrice);
        self::assertSame(139.80, $line->lineTotal);
        self::assertSame('EUR', $summary->currency);
        self::assertSame('/checkout/confirm', $summary->checkoutUrl);
    }

    public function testTheTotalComesFromTheCartAndIsNotResummedFromTheLines(): void
    {
        // Shopware's cart total accounts for shipping, promotions and tax mode. Re-adding the
        // lines would produce a figure that disagrees with the cart page the shopper is about to
        // open — and the assistant would be the one lying. Line sum here is 139.80; the cart
        // says 144.70 because shipping is in it.
        $summary = (new DalCartSummariser())->summarise(
            $this->cart(144.70, $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 2, 69.90)),
            'EUR',
            '/checkout/confirm',
        );

        self::assertSame(144.70, $summary->total);
    }

    public function testItemCountSumsQuantitiesRatherThanCountingLines(): void
    {
        // maxItemQuantity is enforced against the live cart, so a count that reports "2 lines"
        // instead of "5 items" would let a shopper past the guardrail one call at a time.
        $summary = (new DalCartSummariser())->summarise(
            $this->cart(
                349.50,
                $this->productLine('line-1', self::BLACK_M_ID, 'Trail Jersey', 2, 69.90),
                $this->productLine('line-2', 'a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3', 'Trail Jersey', 3, 79.90),
            ),
            'EUR',
            '/checkout/confirm',
        );

        self::assertSame(5, $summary->itemCount);
        self::assertCount(2, $summary->lineItems);
    }

    public function testALineWithNoReferencedProductReportsAnEmptyVariantIdRatherThanItsLineId(): void
    {
        // A promotion line has no referenced product. Reporting its line id as a variant id
        // would let a later blocklist check or add-to-cart act on a value that is not a product.
        $promotion = new LineItem('promo-1', LineItem::PROMOTION_LINE_ITEM_TYPE);
        $promotion->setLabel('Summer discount');
        $promotion->setPrice($this->price(-10.0, 1));

        $summary = (new DalCartSummariser())->summarise($this->cart(129.80, $promotion), 'EUR', '/checkout/confirm');

        $line = $summary->lineItems[0] ?? null;
        self::assertNotNull($line);
        self::assertSame('', $line->variantId);
        self::assertSame('promo-1', $line->lineId);
    }

    public function testALineWithNoCalculatedPriceYetReportsZeroRatherThanFailing(): void
    {
        // A line added but not yet recalculated has no price. A turn must degrade rather than
        // abort — three separate pilot blockers on this branch were foreseeable conditions
        // ending the turn instead of degrading (rulings R48, R49, R52).
        $unpriced = new LineItem('line-1', LineItem::PRODUCT_LINE_ITEM_TYPE, self::BLACK_M_ID, 1);
        $unpriced->setLabel('Trail Jersey');

        $summary = (new DalCartSummariser())->summarise($this->cart(0.0, $unpriced), 'EUR', '/checkout/confirm');

        $line = $summary->lineItems[0] ?? null;
        self::assertNotNull($line);
        self::assertSame(0.0, $line->unitPrice);
        self::assertSame(1, $summary->itemCount);
    }
}
