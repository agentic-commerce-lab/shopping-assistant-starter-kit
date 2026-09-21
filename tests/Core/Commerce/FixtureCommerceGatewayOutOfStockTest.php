<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * `hideOutOfStock` across the fixture gateway's read paths — the half of
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters} the eval suite runs
 * against, so the two gateways cannot drift into answering the same question differently.
 *
 * Fixture units: `fx-011` "Discontinued Rim Brake Pad" is a standalone product at stock 0, and
 * `fx-026-blue-m` is the sold-out Blue/M of the Trail Jersey family.
 */
final class FixtureCommerceGatewayOutOfStockTest extends TestCase
{
    use UsesCatalogFixture;

    private const SOLD_OUT_PRODUCT = 'fx-011';
    private const SOLD_OUT_VARIANT = 'fx-026-blue-m';

    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(self::catalogFixturePath());
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<string>
     */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    public function testWithTheSettingOffASoldOutProductIsStillFound(): void
    {
        $found = $this->gateway()->search(new ProductQuery(term: 'brake pad'), new CatalogScope());

        self::assertContains(self::SOLD_OUT_PRODUCT, self::ids($found));
    }

    public function testWithTheSettingOnASoldOutProductIsNoLongerOffered(): void
    {
        $found = $this->gateway()->search(new ProductQuery(term: 'brake pad'), new CatalogScope(hideOutOfStock: true));

        self::assertNotContains(self::SOLD_OUT_PRODUCT, self::ids($found));
    }

    public function testTheMatchCountDescribesTheSameSetTheSearchDoes(): void
    {
        // They disagree and the assistant advertises "3 more like this" that it will not show.
        $scope = new CatalogScope(hideOutOfStock: true);
        $query = new ProductQuery(term: 'brake pad');

        self::assertSame(
            \count($this->gateway()->search($query, $scope)),
            $this->gateway()->countMatches($query, $scope),
        );
    }

    public function testAShopperWhoNamesTheProductOutrightStillGetsAnAnswerAboutIt(): void
    {
        // The asymmetry is the whole design. A lookup by id that honoured the setting would turn
        // "is that brake pad back in stock?" into "no such product".
        $card = $this->gateway()->product(self::SOLD_OUT_PRODUCT, new CatalogScope(hideOutOfStock: true));

        self::assertNotNull($card);
        self::assertSame(self::SOLD_OUT_PRODUCT, $card->id);
    }

    public function testASoldOutVariantCanStillBeResolvedByItsOptions(): void
    {
        // "Do you have the trail jersey in blue, size M?" — the answer is "that one is sold out",
        // which needs the variant. Hiding it answers "no such product" instead.
        $card = $this->gateway()->resolveVariant(
            'fx-026',
            [new VariantSelection('Blue', 'Colour'), new VariantSelection('M', 'Size')],
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertNotNull($card);
        self::assertSame(self::SOLD_OUT_VARIANT, $card->id);
    }
}
