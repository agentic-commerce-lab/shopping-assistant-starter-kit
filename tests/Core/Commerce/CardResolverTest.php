<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CardResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;

/**
 * Resolving a list of ids to cards, which is what the cards endpoint does when a returning shopper
 * reopens the widget and the stored conversation has to be rehydrated.
 *
 * Phase B measured the old shape: one catalogue lookup per id, 12 ids, 143.8 ms — `CardIdList::MAX_IDS`
 * is 12 precisely because of that loop. This resolver takes one call when the gateway can do it and
 * keeps the loop when it cannot, so a third-party gateway that only implements
 * `CommerceGatewayInterface` still works.
 *
 * @see docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md — Finding 3
 */
final class CardResolverTest extends TestCase
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

    private static function fixture(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testItResolvesEveryKnownId(): void
    {
        $cards = (new CardResolver(self::fixture()))->resolve(['fx-001', 'fx-007', 'fx-008'], new CatalogScope());

        self::assertSame(['fx-001', 'fx-007', 'fx-008'], self::ids($cards));
    }

    /**
     * The order the caller asked for, not the order the database felt like.
     *
     * A batched query returns rows in whatever order the engine chooses, and the widget renders the
     * row in the order it receives — so losing this would silently reshuffle a shopper's card row on
     * every history restore. The loop it replaces preserved order for free, which is exactly how a
     * batching change quietly regresses.
     */
    public function testItPreservesTheRequestedOrder(): void
    {
        $cards = (new CardResolver(self::fixture()))->resolve(['fx-008', 'fx-001', 'fx-007'], new CatalogScope());

        self::assertSame(['fx-008', 'fx-001', 'fx-007'], self::ids($cards));
    }

    /** An id the catalogue no longer holds is skipped, not returned as a hole. */
    public function testItSkipsUnknownIdsWithoutGaps(): void
    {
        $cards = (new CardResolver(self::fixture()))->resolve(
            ['fx-001', 'does-not-exist', 'fx-007'],
            new CatalogScope(),
        );

        self::assertSame(['fx-001', 'fx-007'], self::ids($cards));
    }

    public function testAnEmptyListResolvesToNothingWithoutTouchingTheGateway(): void
    {
        self::assertSame([], (new CardResolver(self::fixture()))->resolve([], new CatalogScope()));
    }

    /**
     * A gateway that implements only `CommerceGatewayInterface` — a merchant's own — must still work.
     * The batch path is an optimisation, never a requirement.
     */
    public function testAGatewayWithoutBatchSupportStillResolves(): void
    {
        $cards = (new CardResolver(new LoopOnlyGateway(self::fixture())))->resolve(
            ['fx-008', 'fx-001'],
            new CatalogScope(),
        );

        self::assertSame(['fx-008', 'fx-001'], self::ids($cards));
    }

    public function testTheBatchPathIsUsedWhenTheGatewaySupportsIt(): void
    {
        $gateway = new CountingBatchGateway(self::fixture());

        (new CardResolver($gateway))->resolve(['fx-001', 'fx-007', 'fx-008'], new CatalogScope());

        self::assertSame(1, $gateway->batchCalls, 'three ids must cost one call, not three');
        self::assertSame(0, $gateway->singleCalls);
    }
}
