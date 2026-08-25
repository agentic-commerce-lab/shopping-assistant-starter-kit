<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\CountingGateway;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * The N+1 the cards endpoint performs is the one cost in this benchmark that is a COUNT rather than a
 * duration, so it is the one that can be asserted. See the class docblock for why this counts at the
 * gateway seam instead of at the SQL layer.
 */
final class CountingGatewayTest extends TestCase
{
    /**
     * Simple products, deliberately.
     *
     * Measured while writing this: `FixtureCommerceGateway::product()` returns null for a parent that
     * has variants (`fx-004`, `fx-026`, `fx-030`), because it indexes sellable units. The counting
     * would be identical either way — a null result is still a call — but an id that resolves to
     * nothing makes a test about the cards endpoint read as though the endpoint returned nothing.
     */
    private const SIMPLE_PRODUCT_IDS = ['fx-001', 'fx-007', 'fx-008'];

    private static function gateway(): CountingGateway
    {
        return new CountingGateway(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'));
    }

    public function testItForwardsSearchAndReturnsTheInnerResult(): void
    {
        $gateway = self::gateway();

        $cards = $gateway->search(new ProductQuery(term: 'Trail Jersey'), new CatalogScope());

        self::assertNotEmpty($cards, 'the decorator must forward, not swallow');
        self::assertSame(1, $gateway->callsTo('search'));
    }

    public function testItCountsOneCallPerLookupWhichIsTheCardsEndpointCost(): void
    {
        $gateway = self::gateway();
        $scope = new CatalogScope();

        // Exactly what AssistantCardController does: one lookup per requested id.
        foreach (self::SIMPLE_PRODUCT_IDS as $id) {
            self::assertNotNull($gateway->product($id, $scope), 'the fixture must actually hold this id');
        }

        self::assertSame(3, $gateway->callsTo('product'));
        self::assertSame(3, $gateway->totalCalls());
    }

    public function testAMethodNeverCalledCountsZero(): void
    {
        self::assertSame(0, self::gateway()->callsTo('resolveVariant'));
        self::assertSame(0, self::gateway()->totalCalls());
    }

    public function testFacetsIsForwardedAndCounted(): void
    {
        $gateway = self::gateway();

        $facets = $gateway->facets(new CatalogScope());

        self::assertNotSame([], $facets->facets);
        self::assertSame(1, $gateway->callsTo('facets'));
    }
}
