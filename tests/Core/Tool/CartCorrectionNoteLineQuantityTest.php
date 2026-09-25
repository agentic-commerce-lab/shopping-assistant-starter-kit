<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote;

/**
 * A variant's quantity is every line that references it, not the first one found.
 *
 * Measured on staging, 2026-09-22: order 10006 held `bk-helmet-gravel-l-black` as three separate
 * lines of one unit each. Reading only the first line made the second add report "0 stored", the
 * model told the shopper there was no stock for a unit that was in the cart, and the card said
 * "In cart (1)" over a cart holding three. Carts written before the adapter set a line id still
 * hold such duplicates, so the sum has to hold even after the write side is fixed.
 */
final class CartCorrectionNoteLineQuantityTest extends TestCase
{
    private const GRAVEL_L = 'c59711ff493fb0702a6df759760bf6a7';
    private const TRAIL_L = '5e22e7ecf34a9f765451d550bce05a7a';

    private static function line(string $lineId, string $variantId, int $quantity): CartLine
    {
        return new CartLine(
            lineId: $lineId,
            variantId: $variantId,
            name: 'Helmet',
            quantity: $quantity,
            unitPrice: 109.0,
            lineTotal: 109.0 * $quantity,
        );
    }

    public function testEveryLineOfTheVariantCounts(): void
    {
        $cart = new CartSummary(lineItems: [
            self::line('random-1', self::GRAVEL_L, 1),
            self::line('random-2', self::GRAVEL_L, 1),
            self::line(self::GRAVEL_L, self::GRAVEL_L, 1),
            self::line(self::TRAIL_L, self::TRAIL_L, 4),
        ]);

        self::assertSame(3, CartCorrectionNote::lineQuantity($cart, self::GRAVEL_L));
    }

    public function testAVariantNotInTheCartHasNone(): void
    {
        $cart = new CartSummary(lineItems: [self::line(self::TRAIL_L, self::TRAIL_L, 4)]);

        self::assertSame(0, CartCorrectionNote::lineQuantity($cart, self::GRAVEL_L));
    }
}
