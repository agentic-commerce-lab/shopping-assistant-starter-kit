<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BasePrice;

/**
 * The division that must land on the same figure the storefront prints.
 *
 * **Verified against a real product page, 2026-09-15.** `VELO-007523`, a 0.25 l chain oil at €6.39,
 * renders as `Content: 0.25 Liter (€25.56 / 1 Liter)` on the storefront. A card printing anything
 * else contradicts the page it links to, which is worse than printing nothing at all.
 */
final class BasePriceTest extends TestCase
{
    /**
     * The measured case, exactly as Shopware renders it.
     */
    public function testItMatchesWhatTheStorefrontPrints(): void
    {
        $base = BasePrice::of(6.39, 0.25, 1.0, 'Liter');

        self::assertNotNull($base);
        self::assertSame(25.56, $base->price);
        self::assertSame(1.0, $base->referenceUnit);
        self::assertSame('Liter', $base->unit);
    }

    /**
     * The four oils this feature exists for: one price tag, a tenfold spread behind it.
     */
    public function testTheSpreadBehindOneIdenticalPrice(): void
    {
        self::assertSame(200.00, BasePrice::of(10.00, 0.05, 1.0, 'Liter')?->price);
        self::assertSame(100.00, BasePrice::of(10.00, 0.10, 1.0, 'Liter')?->price);
        self::assertSame(20.00, BasePrice::of(10.00, 0.50, 1.0, 'Liter')?->price);
    }

    /**
     * Rounded to the cent, because `6.39 / 0.25` is `25.560000000000002` in binary floating point
     * and a card must not print that.
     */
    public function testItRoundsToTheCent(): void
    {
        $base = BasePrice::of(6.39, 0.25, 1.0, 'Liter');

        self::assertNotNull($base);
        self::assertSame('25.56', number_format($base->price, 2, '.', ''));
    }

    /**
     * A reference other than one is carried rather than normalised — `per 100 g` is how a shop
     * quotes some things, and rewriting it as `per 1 g` would be a different claim.
     */
    public function testAReferenceOtherThanOneIsKept(): void
    {
        $base = BasePrice::of(2.49, 0.5, 100.0, 'Gramm');

        self::assertNotNull($base);
        self::assertSame(498.0, $base->price);
        self::assertSame(100.0, $base->referenceUnit);
    }

    /**
     * Most of a catalogue is sold by the piece and has no base price. Null, never a figure — a brake
     * pad with an invented one would be worse than the silence.
     */
    public function testAProductSoldByThePieceHasNone(): void
    {
        self::assertNull(BasePrice::of(4.20, null, null, null));
        self::assertNull(BasePrice::of(4.20, 0.5, 1.0, null), 'no unit name, no base price');
        self::assertNull(BasePrice::of(4.20, null, 1.0, 'Liter'));
    }

    /**
     * Shopware lets `purchaseUnit` be zero. That is a broken product, not a free one, and the honest
     * answer is silence rather than a division by zero.
     */
    public function testAZeroPurchaseUnitDoesNotDivide(): void
    {
        self::assertNull(BasePrice::of(4.20, 0.0, 1.0, 'Liter'));
        self::assertNull(BasePrice::of(4.20, -1.0, 1.0, 'Liter'));
    }
}
