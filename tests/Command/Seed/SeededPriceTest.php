<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\SizeFamily;

/**
 * The seeded price pair, which used to be internally inconsistent.
 *
 * `grossPrice()` wrote `gross` and `net` as the **same** figure, so a 59.00 EUR product at 19% tax
 * was stored as `{"net": 59.0, "gross": 59.0}` — a pair that cannot both be true.
 *
 * On a gross-display storefront nothing looked wrong, which is why it survived. It surfaced through
 * B2B pricing: Shopware Commercial's Individual Pricing applies its percentage to the **net** figure
 * and then re-derives gross, so a merchant-configured +50% surcharge rendered as
 * 59 × 1.5 × 1.19 = 105.32 — an effective +78%. Measured 2026-09-01 on the staging shop, where it
 * read exactly like a Commercial bug and was not one.
 */
final class SeededPriceTest extends TestCase
{
    public function testNetIsDerivedFromGrossRatherThanCopiedFromIt(): void
    {
        $price = SizeFamily::grossPrice(59.00, 19.0);

        self::assertSame(59.00, $price[0]['gross']);
        self::assertSame(49.58, $price[0]['net']);
    }

    /** A zero-rated tax rule leaves the two equal, which is then genuinely correct. */
    public function testAZeroRateLeavesNetEqualToGross(): void
    {
        $price = SizeFamily::grossPrice(59.00, 0.0);

        self::assertSame(59.00, $price[0]['net']);
        self::assertSame(59.00, $price[0]['gross']);
    }

    /**
     * The surcharge arithmetic that made this visible, asserted directly: Commercial computes
     * `net * (1 - amount/100)` and then `gross = net * (1 + rate/100)`. With a correct net, a -50
     * `by_percent` rule is the +50% the merchant asked for.
     */
    public function testACorrectNetMakesACommercialSurchargeLandWhereTheMerchantMeantIt(): void
    {
        $net = SizeFamily::grossPrice(59.00, 19.0)[0]['net'];

        $surchargedGross = round($net * 1.5 * 1.19, 2);

        self::assertSame(88.50, $surchargedGross);
    }
}
