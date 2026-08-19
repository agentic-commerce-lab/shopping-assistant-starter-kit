<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * Split out of {@see FixtureCommerceGatewayTest} (too-many-methods) rather than
 * suppressed: every test here shares one concern — {@see CatalogScope} honoured at
 * every read path, `search()`, `product()` and `resolveVariant()} alike.
 *
 * Finding C1 (seam half): `product()`/`resolveVariant()` did not take a `CatalogScope`
 * at all before this, so a gateway had no way to refuse a blocked product at the
 * point of a direct id lookup — only `search()` ever honoured it. AddToCartTool's own
 * explicit `BlocklistFilter` check (proven separately in AddToCartToolTest) closes the
 * same gap at the tool layer; this closes it at the gateway layer so a future tool
 * cannot forget.
 */
final class FixtureCommerceGatewayScopeTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testSearchExcludesBlockedProducts(): void
    {
        $scope = new CatalogScope(blockedProductIds: ['fx-014']);

        $results = $this->gateway()->search(new ProductQuery(term: 'CO2'), $scope);

        $ids = array_map(static fn($c) => $c->id, $results);
        self::assertNotContains('fx-014', $ids);
    }

    /**
     * blockedProductIds must also block by parentId: a variant-bearing product has
     * no unit keyed by its own product id (only its variants are sellable units),
     * so blocking "fx-026" only has any effect at all if it is matched against each
     * variant's parentId too.
     */
    public function testSearchExcludesAllVariantsOfAParentBlockedByProductId(): void
    {
        $scope = new CatalogScope(blockedProductIds: ['fx-026']);

        $results = $this->gateway()->search(new ProductQuery(term: 'Jersey', limit: 100), $scope);

        $ids = array_map(static fn($c) => $c->id, $results);
        self::assertNotContains('fx-026-blue-m', $ids);
        self::assertNotContains('fx-026-blue-l', $ids);
        self::assertNotContains('fx-026-black-m', $ids);
    }

    public function testResolveVariantHonoursABlockedProductId(): void
    {
        $card = $this->gateway()->resolveVariant(
            'fx-026',
            [new VariantSelection('Blue'), new VariantSelection('M')],
            new CatalogScope(blockedProductIds: ['fx-026-blue-m']),
        );

        self::assertNull($card, 'a blocked variant must never be resolved, even though the selection is unambiguous');
    }

    public function testProductHonoursABlockedProductId(): void
    {
        $card = $this->gateway()->product('fx-014', new CatalogScope(blockedProductIds: ['fx-014']));

        self::assertNull($card);
    }

    public function testProductHonoursABlockedCategory(): void
    {
        $unscoped = $this->gateway()->product('fx-014', new CatalogScope());
        self::assertNotNull($unscoped, 'sanity check: fx-014 exists and is reachable with no scope restriction');

        $card = $this->gateway()->product('fx-014', new CatalogScope(blockedCategoryIds: $unscoped->categoryPath));

        self::assertNull($card);
    }
}
