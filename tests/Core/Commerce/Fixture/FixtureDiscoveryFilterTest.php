<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Fixture;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureDiscoveryFilter;

/**
 * The fixture half of {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters}. Two
 * gateways answering the same question differently is how an eval goes green on behaviour no shop
 * has.
 */
final class FixtureDiscoveryFilterTest extends TestCase
{
    /**
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    private static function unit(string $id, int $stock, StockSource $source = StockSource::Product): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $source === StockSource::Variant ? 'parent' : null,
            name: 'Unit ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: $stock,
            stockSource: $source,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    public function testWithTheSettingOffASoldOutUnitIsStillFound(): void
    {
        $kept = FixtureDiscoveryFilter::apply([
            self::unit('gone', 0),
            self::unit('here', 4),
        ], new CatalogScope());

        self::assertSame(['gone', 'here'], self::ids($kept));
    }

    public function testWithTheSettingOnASoldOutUnitIsNotOffered(): void
    {
        $kept = FixtureDiscoveryFilter::apply([
            self::unit('gone', 0),
            self::unit('here', 4),
        ], new CatalogScope(hideOutOfStock: true));

        self::assertSame(['here'], self::ids($kept));
    }

    public function testASoldOutVariantGoesTheSameWayAsAStandaloneProduct(): void
    {
        // The customer's own example: a size that is not purchasable should stop being offered.
        $kept = FixtureDiscoveryFilter::apply([
            self::unit('size-m', 0, StockSource::Variant),
            self::unit('size-l', 2, StockSource::Variant),
        ], new CatalogScope(hideOutOfStock: true));

        self::assertSame(['size-l'], self::ids($kept));
    }

    public function testAFamilyParentIsJudgedByItsChildrenAndNotByItsOwnStockFigure(): void
    {
        // Same guard DalDiscoveryFilters carries, for the same measured reason: a parent's stock
        // is its own column, not the sum of its children.
        $kept = FixtureDiscoveryFilter::apply([
            self::unit('family', 0, StockSource::Parent),
        ], new CatalogScope(hideOutOfStock: true));

        self::assertSame(['family'], self::ids($kept));
    }
}
