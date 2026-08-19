<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
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

    public function testFacetsExposePriceRangeAndPropertyTerms(): void
    {
        $facets = $this->gateway()->facets(new CatalogScope());

        self::assertTrue($facets->has('price'));
        self::assertTrue($facets->has('properties.Colour'));

        $colourFacet = $facets->get('properties.Colour');
        self::assertNotNull($colourFacet);
        self::assertContains('Blue', $colourFacet->values);

        $priceFacet = $facets->get('price');
        self::assertNotNull($priceFacet);
        self::assertSame(0.0, $priceFacet->min);
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

    public function testResolveVariantReturnsVariantLevelStockNotParentAggregate(): void
    {
        $card = $this->gateway()->resolveVariant(
            'fx-026',
            [
                new VariantSelection('Blue'),
                new VariantSelection('M'),
            ],
            new CatalogScope(),
        );

        self::assertNotNull($card);
        self::assertSame('fx-026-blue-m', $card->id);
        self::assertSame(0, $card->stock, 'must be the variant stock, not the parent aggregate of 15');
        self::assertSame(StockSource::Variant, $card->stockSource);
        self::assertSame(49.90, $card->price);
    }

    public function testResolveVariantUsesVariantPriceWhenItDiffersFromParent(): void
    {
        $card = $this->gateway()->resolveVariant(
            'fx-026',
            [
                new VariantSelection('Black'),
                new VariantSelection('M'),
            ],
            new CatalogScope(),
        );

        self::assertNotNull($card);
        self::assertSame(54.90, $card->price);
        self::assertSame(3, $card->stock);
    }

    public function testResolveVariantReturnsNullWhenSelectionIsAmbiguous(): void
    {
        $card = $this->gateway()->resolveVariant('fx-026', [new VariantSelection('Blue')], new CatalogScope());

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
        $card = $this->gateway()->product('fx-026-blue-m', new CatalogScope());

        self::assertNotNull($card);
        self::assertSame('fx-026-blue-m', $card->id);
        self::assertSame('fx-026', $card->parentId);
        self::assertSame(0, $card->stock);
        self::assertSame(StockSource::Variant, $card->stockSource);
        self::assertSame(49.90, $card->price);
    }

    public function testRetrievalUsesTheCandidateWindowAndNotTheReturnLimit(): void
    {
        // This is the assertion that distinguishes the ordering repair from its
        // mitigation. A gateway applies sort and limit together, so whatever it
        // truncates is gone before VariantResolver can disambiguate it — and the
        // in-stock bias sorts a sold-out unit last, which is exactly the unit a
        // variant question is usually about. Retrieval must therefore read the
        // candidate window; narrowing to `limit` is the caller's job, after
        // resolution.
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        $narrow = $gateway->search(new ProductQuery(term: 'Jersey', limit: 1), new CatalogScope());
        $wide = $gateway->search(new ProductQuery(term: 'Jersey', limit: 1, candidateLimit: 10), new CatalogScope());

        self::assertCount(1, $narrow);
        self::assertGreaterThan(\count($narrow), \count($wide));
    }

    public function testTheSoldOutVariantIsInsideTheCandidateWindowThoughRankingSortsItLast(): void
    {
        // The concrete failure from live run 3, stated as data: ranking puts
        // fx-026-blue-m (stock 0) behind the two in-stock units, so a narrow window
        // drops precisely the variant whose availability the shopper asked about.
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        $cards = $gateway->search(new ProductQuery(term: 'Jersey', limit: 1, candidateLimit: 10), new CatalogScope());

        $ids = array_map(static fn(ProductCard $card): string => $card->id, $cards);
        self::assertContains('fx-026-blue-m', $ids);
    }
}
