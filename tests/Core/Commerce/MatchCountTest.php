<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Commerce\MatchCountReader;

/**
 * The exact match count, independent of the candidate window.
 *
 * `matched` in the tool reply has always been *"a floor, not a census, whenever the candidate window
 * filled up"* — and the window is 50. So on a large catalogue the assistant could not tell 50 matches
 * from 500, which is exactly the difference between *"here are all six occasion dresses"* and *"here
 * are four of three hundred"*. Only one of those is a shortlist.
 */
final class MatchCountTest extends TestCase
{
    public function testTheFixtureGatewayCanCount(): void
    {
        self::assertInstanceOf(MatchCountReader::class, $this->gateway());
    }

    public function testItCountsEverythingTheQueryMatchesNotJustTheLimit(): void
    {
        $gateway = $this->gateway();
        $scope = new CatalogScope();

        $returned = \count($gateway->search(new ProductQuery(term: 'jersey', limit: 2), $scope));
        $counted = $gateway->countMatches(new ProductQuery(term: 'jersey', limit: 2), $scope);

        self::assertSame(2, $returned, 'the limit still bounds what search returns');
        self::assertGreaterThan($returned, $counted, 'the count must ignore the limit');
    }

    /** The count must describe the same set the scope allows, or it advertises what the shopper may not see. */
    public function testABlockedProductIsNotCounted(): void
    {
        $gateway = $this->gateway();
        $query = new ProductQuery(term: 'jersey', limit: 8);

        $all = $gateway->countMatches($query, new CatalogScope());
        $blocked = $gateway->countMatches($query, new CatalogScope(blockedProductIds: ['fx-026']));

        self::assertLessThan($all, $blocked);
    }

    public function testAQueryMatchingNothingCountsZero(): void
    {
        self::assertSame(0, $this->gateway()->countMatches(
            new ProductQuery(term: 'zzzznotathing'),
            new CatalogScope(),
        ));
    }

    private function gateway(): FixtureCommerceGateway&MatchCountReader
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        self::assertInstanceOf(MatchCountReader::class, $gateway);

        return $gateway;
    }
}
