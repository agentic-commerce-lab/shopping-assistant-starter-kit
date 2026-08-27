<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave;
use Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier;

/**
 * `FamilyDiversifier::of()` — see `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`,
 * decision F2, for the algorithm this locks in: one card per family first (in existing relevance
 * order), then backfill from already-shown families if slots remain.
 */
// @mago-expect lint:too-many-methods
// `family()`, `standalone()` and `ids()` are fixture-building helpers, not test cases — an earlier
// version of this file moved them to namespace-scope functions to dodge this finding, which made them
// the only file-scope functions in the entire suite and a real redeclaration risk for any other test
// file sharing this namespace. Kept here as private static methods instead, matching every other test
// file's own helper convention (e.g. `SearchProductsToolNarrowingTest::ids()`,
// `CandidateInterleaveTest`'s `card()`/`ids()`).
final class FamilyDiversifierTest extends TestCase
{
    public function testASingleFamilyIsATrueNoOp(): void
    {
        // Five variants of one family, already in relevance order. With only one family,
        // diversifying has nothing to diversify — the output must equal a plain
        // array_slice(survivors, 0, limit), byte for byte.
        $cards = self::family('parent-a', 5);

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-a-1', 'parent-a-2'], self::ids($result));
    }

    public function testMultipleFamiliesAreDiversifiedBeforeAnyBackfill(): void
    {
        // Family A's five variants rank first (adjacent, same name), then family B's two.
        // Requesting 3 must surface both families, not exhaust family A first.
        $cards = [...self::family('parent-a', 5), ...self::family('parent-b', 2)];

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-b-0', 'parent-a-1'], self::ids($result));
    }

    public function testBackfillNeverReturnsFewerThanArraySliceWould(): void
    {
        // Only two distinct families exist. Asking for 5 must still return 5 — the shopper
        // never sees fewer cards than plain top-N would have shown just because diversity
        // ran out (spec F2's explicit requirement).
        $cards = [...self::family('parent-a', 3), ...self::family('parent-b', 3)];

        $result = FamilyDiversifier::of($cards, 5);

        self::assertCount(5, $result);
        self::assertSame(['parent-a-0', 'parent-b-0', 'parent-a-1', 'parent-a-2', 'parent-b-1'], self::ids($result));
    }

    public function testStandaloneProductsAreEachTheirOwnFamily(): void
    {
        // Two products with no parentId are two different things, not "a family of one"
        // to be collapsed together — spec F3, deliberately unlike RedundantParentFilter's
        // and TruncatedFamilies' notion of family.
        $cards = [self::standalone('fx-001'), self::standalone('fx-002'), self::standalone('fx-003')];

        $result = FamilyDiversifier::of($cards, 2);

        self::assertSame(['fx-001', 'fx-002'], self::ids($result));
    }

    public function testFamilyKeyIsParentIdOrOwnId(): void
    {
        $familyCards = self::family('parent-a', 1);
        $variant = $familyCards[0] ?? null;
        \assert($variant instanceof ProductCard, 'family() must return at least one card when count > 0.');
        $standaloneCard = self::standalone('fx-001');

        self::assertSame('parent-a', FamilyDiversifier::familyKey($variant));
        self::assertSame('fx-001', FamilyDiversifier::familyKey($standaloneCard));
    }

    public function testEmptySurvivorsReturnsEmpty(): void
    {
        self::assertSame([], FamilyDiversifier::of([], 5));
    }

    public function testALimitOfZeroReturnsEmpty(): void
    {
        self::assertSame([], FamilyDiversifier::of(self::family('parent-a', 3), 0));
    }

    public function testFewerSurvivorsThanTheLimitReturnsAllOfThem(): void
    {
        $cards = self::family('parent-a', 2);

        $result = FamilyDiversifier::of($cards, 5);

        self::assertSame(['parent-a-0', 'parent-a-1'], self::ids($result));
    }

    public function testOneTermsManyFamiliesSkewsThePastAnotherTermsOneLargeFamily(): void
    {
        // Pins the skew documented in CandidateInterleave's docblock: FamilyDiversifier has no
        // notion of "term", so when one term contributes many distinct single-card families and
        // another contributes one family with many variants, the diversifier's demotion can
        // systematically favour the many-family term over the many-variant one.
        //
        // Survivors, built the way CandidateInterleave actually produces them for two terms —
        // "term A" (four distinct dress families, one card each) interleaved with "term B" (one
        // suit family, four variants): dA-0, S-0, dB-0, S-1, dC-0, S-2, dD-0, S-3.
        //
        // A plain array_slice(survivors, 0, 4) would give dA-0, S-0, dB-0, S-1 — 2 of each term,
        // balanced. FamilyDiversifier::of() instead demotes S-1 behind dC-0 — a different term's
        // card entirely, because FamilyDiversifier never looks at which term produced a card.
        $dresses = [
            ...self::family('dA', 1),
            ...self::family('dB', 1),
            ...self::family('dC', 1),
            ...self::family('dD', 1),
        ];
        $suits = self::family('S', 4);

        $survivors = CandidateInterleave::of([$dresses, $suits], cap: 8);

        $result = FamilyDiversifier::of($survivors, 4);

        // 3 dresses, 1 suit — not the balanced 2-and-2 a plain prefix would have kept.
        self::assertSame(['dA-0', 'S-0', 'dB-0', 'dC-0'], self::ids($result));
    }

    /** @return list<ProductCard> */
    private static function family(string $parentId, int $count): array
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

    private static function standalone(string $id): ProductCard
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
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }
}
