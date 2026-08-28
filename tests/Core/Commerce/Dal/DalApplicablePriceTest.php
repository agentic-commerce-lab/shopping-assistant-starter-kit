<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalApplicablePrice;

/**
 * The tier collection Shopware hands us is not a list of ranges — it is a list of prices each
 * tagged with `quantityEnd ?? quantityStart`. Every case here exists because a plausible reading
 * of that collection gets it wrong for some quantity a B2B shopper will actually type.
 */
final class DalApplicablePriceTest extends TestCase
{
    private function tier(float $unitPrice, int $quantity): CalculatedPrice
    {
        return new CalculatedPrice(
            $unitPrice,
            $unitPrice * $quantity,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            $quantity,
        );
    }

    /**
     * 1–9 at 79.90, 10–49 at 74.90, 50+ at 70.00 — the shape Shopware stores for a graduated
     * price with an open-ended top tier. Note the third entry carries 50, its START, because it
     * has no `quantityEnd`.
     */
    private function graduated(): PriceCollection
    {
        return new PriceCollection([
            $this->tier(79.90, 9),
            $this->tier(74.90, 49),
            $this->tier(70.00, 50),
        ]);
    }

    public function testAnEmptyCollectionSelectsNothingSoTheCallerCanFallBack(): void
    {
        self::assertNull((new DalApplicablePrice())->forQuantity(new PriceCollection(), 1));
    }

    public function testASingleTierAppliesAtEveryQuantity(): void
    {
        $tiers = new PriceCollection([$this->tier(59.90, 1)]);

        self::assertSame(
            59.90,
            (new DalApplicablePrice())
                ->forQuantity($tiers, 1)
                ?->getUnitPrice(),
        );
        self::assertSame(
            59.90,
            (new DalApplicablePrice())
                ->forQuantity($tiers, 500)
                ?->getUnitPrice(),
        );
    }

    public function testTheFirstTierAppliesBelowTheSecondTiersStart(): void
    {
        self::assertSame(
            79.90,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 1)
                ?->getUnitPrice(),
        );
        self::assertSame(
            79.90,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 9)
                ?->getUnitPrice(),
        );
    }

    public function testTheSecondTierAppliesFromItsFirstUnitToItsLast(): void
    {
        self::assertSame(
            74.90,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 10)
                ?->getUnitPrice(),
        );
        self::assertSame(
            74.90,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 49)
                ?->getUnitPrice(),
        );
    }

    public function testAQuantityAboveTheOpenEndedTiersStartStillSelectsIt(): void
    {
        // The case the obvious implementation misses. The top entry carries 50 — its START — so
        // "the first entry whose quantity is at least 120" matches nothing and a bulk order gets
        // no price at all, or worse, silently falls back to the single-unit figure.
        self::assertSame(
            70.00,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 50)
                ?->getUnitPrice(),
        );
        self::assertSame(
            70.00,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 120)
                ?->getUnitPrice(),
        );
    }

    public function testAQuantityBelowOneIsTreatedAsOneRatherThanSelectingNothing(): void
    {
        // A malformed argument must degrade to the ordinary single-unit answer, not to a null
        // price beside a product the shopper is looking at.
        self::assertSame(
            79.90,
            (new DalApplicablePrice())
                ->forQuantity($this->graduated(), 0)
                ?->getUnitPrice(),
        );
    }
}
