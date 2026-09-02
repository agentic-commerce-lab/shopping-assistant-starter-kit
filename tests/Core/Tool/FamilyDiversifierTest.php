<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave;
use Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier;

/**
 * `FamilyDiversifier::of()` — at most one card per family, in existing relevance order, capped at the
 * requested limit.
 *
 * **Spec decision F2 originally added a second pass** that backfilled from already-shown families
 * whenever the first pass left slots free, on the rule that *"a shopper never sees fewer cards than a
 * plain `array_slice` would have shown"*. That rule was reversed on 2026-09-02, measured on the
 * staging shop: *"I am looking for a good jacket"* matched 18 variants across **three** families, the
 * model asked for five cards, and the backfill made two of the five a second and third Packable Rain
 * Jacket. Whether the shopper then saw the same jacket three times came down to the model — one run
 * de-duplicated them, another listed them — which is how a retrieval defect came to be reported as a
 * model defect.
 *
 * The backfill was never buying what it claimed. `TruncatedFamilies` runs immediately after this and
 * discloses every family that was cut, with its option values: dropping a duplicate card does not
 * hide the variant, it moves it from a card that reads as a separate product into a disclosure that
 * reads as an option. Fewer cards, and the same facts.
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
    public function testASingleFamilyIsOneCard(): void
    {
        // Five variants of one product. Three cards here is three rows with the same name and the
        // same picture, which reads as three products rather than as one in three sizes — and the
        // sizes are disclosed anyway by TruncatedFamilies.
        $cards = self::family('parent-a', 5);

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0'], self::ids($result));
    }

    public function testEveryFamilyIsRepresentedOnceAndTheLimitIsACeilingNotAQuota(): void
    {
        // Family A's five variants rank first (adjacent, same name), then family B's two. Requesting
        // 3 surfaces both families and stops at two cards: the third slot has nothing new to put in
        // it, and filling it means repeating family A.
        $cards = [...self::family('parent-a', 5), ...self::family('parent-b', 2)];

        $result = FamilyDiversifier::of($cards, 3);

        self::assertSame(['parent-a-0', 'parent-b-0'], self::ids($result));
    }

    public function testTheSameProductIsNeverRepeatedToFillTheLimit(): void
    {
        // The measured failure, reduced: two families, five slots. The old second pass filled the
        // remaining three with more of family A and B, and the model was handed five cards of which
        // three were duplicates. Two families can only honestly answer with two cards.
        $cards = [...self::family('parent-a', 3), ...self::family('parent-b', 3)];

        $result = FamilyDiversifier::of($cards, 5);

        self::assertSame(['parent-a-0', 'parent-b-0'], self::ids($result));
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

    public function testFewerFamiliesThanTheLimitReturnsOneCardEach(): void
    {
        // Two survivors, but one product. The limit is not a target to be reached.
        $cards = self::family('parent-a', 2);

        $result = FamilyDiversifier::of($cards, 5);

        self::assertSame(['parent-a-0'], self::ids($result));
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
