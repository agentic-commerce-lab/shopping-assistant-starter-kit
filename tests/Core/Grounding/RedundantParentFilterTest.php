<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\RedundantParentFilter;

/**
 * Measured on the live shop, 2026-08-21: *"what bib shorts do you sell?"* rendered three cards —
 * Black/L at 7 in stock, Black/M at 5, and then the family parent at 79.00 with no options, "In
 * stock", no add button and a note explaining that its figure covers every variant. A shopper reads
 * that third row as a duplicate of the two above it, or worse, as a third product.
 *
 * The parent earns its place only when it is the sole thing the shop can say. When its own children
 * are on screen it is the strictly less informative card and the disclosure is answering a question
 * nobody now has.
 */
final class RedundantParentFilterTest extends TestCase
{
    private const PARENT_ID = 'fafafafafafafafafafafafafafafafa';

    private const OTHER_PARENT_ID = 'fbfbfbfbfbfbfbfbfbfbfbfbfbfbfbfb';

    private static function card(string $id, ?string $parentId, StockSource $source): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $parentId,
            name: 'Bib Shorts',
            description: null,
            price: 79.0,
            currency: 'EUR',
            stock: 12,
            stockSource: $source,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    /** @param list<ProductCard> $cards */
    private static function ids(array $cards): array
    {
        return array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    public function testTheParentIsDroppedWhenItsOwnChildIsInTheSameSet(): void
    {
        $cards = [
            self::card('child-l', self::PARENT_ID, StockSource::Variant),
            self::card(self::PARENT_ID, null, StockSource::Parent),
            self::card('child-m', self::PARENT_ID, StockSource::Variant),
        ];

        self::assertSame(['child-l', 'child-m'], self::ids(RedundantParentFilter::apply($cards)));
    }

    /**
     * The case the disclosure exists for. A parent alone is all the shop can say, and dropping it
     * would turn a careful answer into no answer.
     */
    public function testTheParentSurvivesWhenNoneOfItsChildrenAreThere(): void
    {
        $cards = [self::card(self::PARENT_ID, null, StockSource::Parent)];

        self::assertSame([self::PARENT_ID], self::ids(RedundantParentFilter::apply($cards)));
    }

    /** Another family's variants say nothing about this family. */
    public function testAnUnrelatedVariantDoesNotDisplaceAParent(): void
    {
        $cards = [
            self::card(self::PARENT_ID, null, StockSource::Parent),
            self::card('other-child', self::OTHER_PARENT_ID, StockSource::Variant),
        ];

        self::assertSame([self::PARENT_ID, 'other-child'], self::ids(RedundantParentFilter::apply($cards)));
    }

    /**
     * A standalone product is not a parent of anything, and must never be removed by a rule about
     * families — that would delete the answer to "do you sell bottle cages?".
     */
    public function testAStandaloneProductIsNeverTouched(): void
    {
        $cards = [
            self::card('fx-017', null, StockSource::Product),
            self::card('child-l', self::PARENT_ID, StockSource::Variant),
        ];

        self::assertSame(['fx-017', 'child-l'], self::ids(RedundantParentFilter::apply($cards)));
    }

    public function testOrderIsPreservedAndAnEmptySetStaysEmpty(): void
    {
        self::assertSame([], RedundantParentFilter::apply([]));
    }
}
