<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

final class FixtureCommerceGatewayTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    /**
     * @mago-expect analysis:possibly-null-property-access
     * @mago-expect analysis:possibly-null-argument
     *
     * The brief's own `has()` check on the previous lines already guarantees these
     * facets exist; `get()` stays nullable in its own right (a facet that was never
     * asserted present could be absent), so the property access below is safe here.
     */
    public function testFacetsExposePriceRangeAndPropertyTerms(): void
    {
        $facets = $this->gateway()->facets(new CatalogScope());

        self::assertTrue($facets->has('price'));
        self::assertTrue($facets->has('properties.Colour'));
        self::assertContains('Blue', $facets->get('properties.Colour')->values);
        self::assertSame(0.0, $facets->get('price')->min);
    }

    public function testSearchAppliesPriceRangeFilter(): void
    {
        $query = new ProductQuery(term: null, filters: [new FilterClause('price', FilterOperator::Range, [
            'lte' => 20.00,
        ])]);

        $results = $this->gateway()->search($query, new CatalogScope());

        self::assertNotEmpty($results);
        foreach ($results as $card) {
            self::assertLessThanOrEqual(20.00, $card->price);
        }
    }

    public function testSearchExcludesBlockedProducts(): void
    {
        $scope = new CatalogScope(blockedProductIds: ['fx-014']);

        $results = $this->gateway()->search(new ProductQuery(term: 'CO2'), $scope);

        $ids = array_map(static fn($c) => $c->id, $results);
        self::assertNotContains('fx-014', $ids);
    }

    public function testResolveVariantReturnsVariantLevelStockNotParentAggregate(): void
    {
        $card = $this->gateway()->resolveVariant('fx-026', [
            new VariantSelection('Blue'),
            new VariantSelection('M'),
        ]);

        self::assertNotNull($card);
        self::assertSame('fx-026-blue-m', $card->id);
        self::assertSame(0, $card->stock, 'must be the variant stock, not the parent aggregate of 15');
        self::assertSame(StockSource::Variant, $card->stockSource);
        self::assertSame(49.90, $card->price);
    }

    public function testResolveVariantUsesVariantPriceWhenItDiffersFromParent(): void
    {
        $card = $this->gateway()->resolveVariant('fx-026', [
            new VariantSelection('Black'),
            new VariantSelection('M'),
        ]);

        self::assertNotNull($card);
        self::assertSame(54.90, $card->price);
        self::assertSame(3, $card->stock);
    }

    public function testResolveVariantReturnsNullWhenSelectionIsAmbiguous(): void
    {
        $card = $this->gateway()->resolveVariant('fx-026', [new VariantSelection('Blue')]);

        self::assertNull($card, 'Blue alone matches both M and L — must not guess');
    }

    public function testAddToCartAccumulatesAndReportsTotals(): void
    {
        $gateway = $this->gateway();
        $gateway->addToCart('fx-026-blue-l', 2);
        $cart = $gateway->addToCart('fx-017', 1);

        self::assertSame(3, $cart->itemCount);
        self::assertSame(112.70, round($cart->total, precision: 2));
    }

    /**
     * Ruling R3: product() must resolve variant ids as well as parent product ids,
     * because the fixture index holds sellable units (each variant is its own unit).
     * Tasks 9 and 11 call product() directly with a variant id and expect that
     * variant's own price, stock and StockSource::Variant — not the parent's.
     */
    public function testProductResolvesByVariantIdWithVariantOwnStockAndPrice(): void
    {
        $card = $this->gateway()->product('fx-026-blue-m');

        self::assertNotNull($card);
        self::assertSame('fx-026-blue-m', $card->id);
        self::assertSame('fx-026', $card->parentId);
        self::assertSame(0, $card->stock);
        self::assertSame(StockSource::Variant, $card->stockSource);
        self::assertSame(49.90, $card->price);
    }
}
