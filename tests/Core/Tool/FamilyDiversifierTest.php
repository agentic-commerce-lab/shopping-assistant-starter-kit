<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier;

/**
 * `FamilyDiversifier::of()` — see `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`,
 * decision F2, for the algorithm this locks in: one card per family first (in existing relevance
 * order), then backfill from already-shown families if slots remain.
 */
final class FamilyDiversifierTest extends TestCase
{
    public function testASingleFamilyIsATrueNoOp(): void
    {
        // Five variants of one family, already in relevance order. With only one family,
        // diversifying has nothing to diversify — the output must equal a plain
        // array_slice(survivors, 0, limit), byte for byte.
        $cards = family('parent-a', 5);

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-a-1', 'parent-a-2'], ids($result));
    }

    public function testMultipleFamiliesAreDiversifiedBeforeAnyBackfill(): void
    {
        // Family A's five variants rank first (adjacent, same name), then family B's two.
        // Requesting 3 must surface both families, not exhaust family A first.
        $cards = [...family('parent-a', 5), ...family('parent-b', 2)];

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-b-0', 'parent-a-1'], ids($result));
    }

    public function testBackfillNeverReturnsFewerThanArraySliceWould(): void
    {
        // Only two distinct families exist. Asking for 5 must still return 5 — the shopper
        // never sees fewer cards than plain top-N would have shown just because diversity
        // ran out (spec F2's explicit requirement).
        $cards = [...family('parent-a', 3), ...family('parent-b', 3)];

        $result = FamilyDiversifier::of($cards, 5);

        self::assertCount(5, $result);
        self::assertSame(['parent-a-0', 'parent-b-0', 'parent-a-1', 'parent-a-2', 'parent-b-1'], ids($result));
    }

    public function testStandaloneProductsAreEachTheirOwnFamily(): void
    {
        // Two products with no parentId are two different things, not "a family of one"
        // to be collapsed together — spec F3, deliberately unlike RedundantParentFilter's
        // and TruncatedFamilies' notion of family.
        $cards = [standalone('fx-001'), standalone('fx-002'), standalone('fx-003')];

        $result = FamilyDiversifier::of($cards, 2);

        self::assertSame(['fx-001', 'fx-002'], ids($result));
    }

    public function testFamilyKeyIsParentIdOrOwnId(): void
    {
        $familyCards = family('parent-a', 1);
        $variant = $familyCards[0] ?? null;
        \assert($variant instanceof ProductCard, 'family() must return at least one card when count > 0.');
        $standaloneCard = standalone('fx-001');

        self::assertSame('parent-a', FamilyDiversifier::familyKey($variant));
        self::assertSame('fx-001', FamilyDiversifier::familyKey($standaloneCard));
    }

    public function testEmptySurvivorsReturnsEmpty(): void
    {
        self::assertSame([], FamilyDiversifier::of([], 5));
    }

    public function testALimitOfZeroReturnsEmpty(): void
    {
        self::assertSame([], FamilyDiversifier::of(family('parent-a', 3), 0));
    }

    public function testFewerSurvivorsThanTheLimitReturnsAllOfThem(): void
    {
        $cards = family('parent-a', 2);

        $result = FamilyDiversifier::of($cards, 5);

        self::assertSame(['parent-a-0', 'parent-a-1'], ids($result));
    }
}

/** @return list<ProductCard> */
function family(string $parentId, int $count): array
{
    $cards = [];

    for ($i = 0; $i < $count; ++$i) {
        $cards[] = new ProductCard(
            id: $parentId . '-' . $i,
            parentId: $parentId,
            name: 'Card',
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $parentId . '-' . $i,
            imageUrl: null,
        );
    }

    return $cards;
}

function standalone(string $id): ProductCard
{
    return new ProductCard(
        id: $id,
        parentId: null,
        name: 'Card',
        description: null,
        price: 10.0,
        currency: 'EUR',
        stock: 5,
        stockSource: StockSource::Product,
        deliveryTime: null,
        url: '/detail/' . $id,
        imageUrl: null,
    );
}

/**
 * @param list<ProductCard> $cards
 *
 * @return list<string>
 */
function ids(array $cards): array
{
    return array_map(static fn(ProductCard $card): string => $card->id, $cards);
}
