<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * The fixture half of {@see \Swag\AssistantStarterKit\Core\Commerce\StockedFamilyLookup}, which
 * {@see \Swag\AssistantStarterKit\Core\Policy\UnbuyableFamilies} asks once per result.
 *
 * Fixture family `fx-026` Trail Jersey: Blue/M sold out, Blue/L and Black/M stocked. Blocking those
 * two is how a catalogue with no fully-sold-out family can still describe one.
 */
final class FixtureStockedFamiliesTest extends TestCase
{
    use UsesCatalogFixture;

    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(self::catalogFixturePath());
    }

    public function testAFamilyWithOneStockedVariantIsReportedAsBuyable(): void
    {
        self::assertSame(['fx-026'], $this->gateway()->familiesWithStock(['fx-026'], new CatalogScope()));
    }

    public function testAFamilyWhoseEveryStockedVariantIsBlockedCountsAsUnbuyable(): void
    {
        // Through the scope, like every other family read. A blocked variant is not stock the
        // assistant may offer, so a family held up only by blocked variants is not buyable either.
        $buyable = $this->gateway()->familiesWithStock(
            ['fx-026'],
            new CatalogScope(blockedProductIds: ['fx-026-blue-l', 'fx-026-black-m'], hideOutOfStock: true),
        );

        self::assertSame([], $buyable);
    }

    public function testAnIdThatIsNotAFamilyAtAllIsSimplyAbsent(): void
    {
        // `fx-011` is a standalone product. Reporting it as buyable would keep a card the filter
        // was asked to judge; reporting it as unbuyable would drop one it never owned.
        self::assertSame([], $this->gateway()->familiesWithStock(['fx-011'], new CatalogScope()));
    }

    public function testAskingAboutNothingAsksTheCatalogueNothing(): void
    {
        self::assertSame([], $this->gateway()->familiesWithStock([], new CatalogScope()));
    }
}
