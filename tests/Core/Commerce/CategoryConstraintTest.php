<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * A page category narrows a search and can never widen one.
 *
 * The distinction this file defends: `CatalogScope::$includeCategoryIds` is OR-ed, so a
 * client-supplied value there would let a shopper reach products a merchant excluded. A
 * `ProductQuery` constraint is AND-ed with the scope, so the worst it can do is return nothing.
 */
final class CategoryConstraintTest extends TestCase
{
    use UsesCatalogFixture;

    public function testAConstrainedSearchReturnsOnlyProductsInThatCategory(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $category = self::aCategoryInTheFixture($gateway);

        $cards = $gateway->search(new ProductQuery(limit: 50, categoryId: $category), new CatalogScope());

        self::assertNotEmpty($cards, 'the fixture category must contain something, or this asserts nothing');

        foreach ($cards as $card) {
            self::assertContains($category, $card->categoryPath);
        }
    }

    public function testTheConstraintNarrowsWithinTheMerchantScopeRatherThanEscapingIt(): void
    {
        // The whole of P8: a shopper standing in a category a merchant excluded gets nothing, not
        // access. If this ever returns rows, page context has become a policy bypass.
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $category = self::aCategoryInTheFixture($gateway);

        $cards = $gateway->search(
            new ProductQuery(limit: 50, categoryId: $category),
            new CatalogScope(excludeCategoryIds: [$category]),
        );

        self::assertSame([], $cards);
    }

    public function testNoConstraintSearchesEverythingInScope(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());

        self::assertNotEmpty($gateway->search(new ProductQuery(limit: 50), new CatalogScope()));
    }

    private static function aCategoryInTheFixture(FixtureCommerceGateway $gateway): string
    {
        foreach ($gateway->search(new ProductQuery(limit: 50), new CatalogScope()) as $card) {
            if ($card->categoryPath !== []) {
                return $card->categoryPath[0];
            }
        }

        self::fail('The catalogue fixture has no categorised product; this feature cannot be tested against it.');
    }
}
