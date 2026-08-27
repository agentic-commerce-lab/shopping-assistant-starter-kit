<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the four traps are present, and — for `fw-occasion-word` — that its premise holds over the
 * WHOLE encoded catalogue rather than over the trap file.
 *
 * That distinction is the point of this class. A generated product name, a colour, a material or a
 * category that happened to contain the shopper's word would quietly turn the central measurement into
 * a different experiment, and every other test here would still be green.
 *
 * @phpstan-import-type FashionCatalogue from FashionCatalogGenerator
 */
final class FashionTrapPresenceTest extends TestCase
{
    /**
     * Trap `fw-occasion-word`.
     *
     * The shopper says "wedding". Exactly one product in ~3,600 carries that word, and it is a charm
     * that cannot be worn — so a keyword search either finds nothing to wear, or finds the charm.
     */
    public function testOnlyOneProductInTheWholeCatalogueMentionsTheOccasion(): void
    {
        self::assertSame(
            [FashionTrapProducts::FALSE_FRIEND_ID],
            FashionCatalogText::idsMentioning(FashionCatalogQuery::built(), 'wedding'),
        );
    }

    /** And no product mentions the adjacent word either, which a search might plausibly stem to. */
    public function testNoProductMentionsTheAdjacentWord(): void
    {
        self::assertSame([], FashionCatalogText::idsMentioning(FashionCatalogQuery::built(), 'bridal'));
    }

    /** And the one that does carry it is not a garment. */
    public function testTheOnlyMentionIsNotSomethingYouCanWear(): void
    {
        $charm = FashionCatalogQuery::find(FashionCatalogQuery::built(), FashionTrapProducts::FALSE_FRIEND_ID);

        self::assertNotNull($charm);
        self::assertSame(['Gifts & Novelty', 'Keepsakes'], $charm['categoryPath']);
        self::assertSame([], $charm['variants']);
    }

    /**
     * Trap `fw-gender-split`: both sides stocked, in disjoint branches, with no unisex overlap.
     *
     * This is what makes asking the CORRECT behaviour for the wedding journey — the answer set really
     * does change with the answer.
     */
    public function testTheGenderSplitIsDisjointAndBothSidesAreStocked(): void
    {
        $catalogue = FashionCatalogQuery::built();
        $dresses = FashionCatalogQuery::withIdPrefix($catalogue, FashionTrapProducts::OCCASION_DRESS_PREFIX);
        $suits = FashionCatalogQuery::withIdPrefix($catalogue, FashionTrapProducts::OCCASION_SUIT_PREFIX);

        self::assertCount(6, $dresses);
        self::assertCount(6, $suits);

        foreach ($dresses as $dress) {
            self::assertSame(['Women', 'Occasion & Party', 'Occasion Dresses'], $dress['categoryPath']);
        }

        foreach ($suits as $suit) {
            self::assertSame(['Men', 'Suits & Tailoring', 'Occasion Suits'], $suit['categoryPath']);
        }
    }

    /**
     * Trap `fw-undivided`, the negative control: yoga wear under exactly one department, so nothing the
     * shopper could say changes the answer and asking buys only friction.
     */
    public function testYogaWearExistsUnderExactlyOneDepartment(): void
    {
        $departments = [];

        foreach (FashionCatalogQuery::built()['products'] as $product) {
            if (\in_array('Yoga', $product['categoryPath'], strict: true)) {
                $departments[$product['categoryPath'][0] ?? ''] = true;
            }
        }

        self::assertSame(['Women'], array_keys($departments));
    }

    /**
     * The trap the whole design turns on, stated as a catalogue property rather than as prose: there is
     * no category named after the occasion either. An assistant that can only match words has nothing
     * to match.
     */
    public function testNoCategoryIsNamedAfterTheOccasion(): void
    {
        foreach (array_keys(FashionCatalogQuery::categoryNodes(FashionCatalogQuery::built())) as $node) {
            self::assertStringNotContainsStringIgnoringCase('wedding', $node);
            self::assertStringNotContainsStringIgnoringCase('bridal', $node);
        }
    }
}
