<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Policy\UnbuyableFamilies;
use Swag\AssistantStarterKit\Tests\Core\Commerce\RecordingFamilyLookup;

/**
 * Found on staging 2026-09-21, not in any test: with `hideOutOfStock` on, every sold-out variant of
 * "Long Finger Gloves" was correctly withheld and the FAMILY still came back as a card — picture,
 * price and all — for a product no shopper could buy in any size.
 *
 * The guard in {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters} is why: a
 * parent row is spared because its own stock column says nothing about its children. That is right,
 * and it leaves this question open. Answering it needs the children, which a criteria filter does
 * not have.
 */
final class UnbuyableFamiliesTest extends TestCase
{
    private function card(string $id, StockSource $source, int $stock = 0): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $source === StockSource::Variant ? 'some-parent' : null,
            name: 'Card ' . $id,
            description: null,
            price: 29.9,
            currency: 'EUR',
            stock: $stock,
            stockSource: $source,
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

    public function testAFamilyNobodyCanBuyASizeOfIsNotOffered(): void
    {
        $result = (new UnbuyableFamilies())->apply(
            [$this->card('gloves', StockSource::Parent)],
            new RecordingFamilyLookup([]),
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame([], self::ids($result['cards']));
        self::assertSame(['gloves'], $result['removed']);
    }

    public function testAFamilyWithOneSizeLeftStaysExactlyWhereItWas(): void
    {
        $result = (new UnbuyableFamilies())->apply(
            [$this->card('jersey', StockSource::Parent), $this->card('gloves', StockSource::Parent)],
            new RecordingFamilyLookup(['jersey']),
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(['jersey'], self::ids($result['cards']));
    }

    public function testAVariantAndAStandaloneProductAreNeverJudgedByThisFilter(): void
    {
        // Both carry their OWN stock, so `DalDiscoveryFilters` already decided about them at
        // retrieval. Re-deciding here would be a second opinion on a settled question, and one
        // taken without their family.
        $cards = [
            $this->card('variant', StockSource::Variant, stock: 0),
            $this->card('simple', StockSource::Product, stock: 0),
        ];

        $result = (new UnbuyableFamilies())->apply(
            $cards,
            new RecordingFamilyLookup([]),
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(['variant', 'simple'], self::ids($result['cards']));
    }

    public function testWithTheSettingOffASoldOutFamilyIsStillOffered(): void
    {
        // Same switch, same meaning: a shop that shows products it has to reorder shows families
        // it has to reorder. Nothing here is a correctness rule of its own.
        $result = (new UnbuyableFamilies())->apply(
            [$this->card('gloves', StockSource::Parent)],
            new RecordingFamilyLookup([]),
            new CatalogScope(),
        );

        self::assertSame(['gloves'], self::ids($result['cards']));
    }

    public function testEveryFamilyInOneResultCostsOneLookupBetweenThem(): void
    {
        // A query per card would put a round trip on every product of every search. The whole
        // reason the lookup takes a list.
        $lookup = new RecordingFamilyLookup(['a']);

        (new UnbuyableFamilies())->apply(
            [
                $this->card('a', StockSource::Parent),
                $this->card('b', StockSource::Parent),
                $this->card('c', StockSource::Parent),
            ],
            $lookup,
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(1, $lookup->calls);
    }

    public function testAResultWithNoFamiliesAsksTheShopNothing(): void
    {
        $lookup = new RecordingFamilyLookup([]);

        (new UnbuyableFamilies())->apply(
            [$this->card('simple', StockSource::Product, stock: 3)],
            $lookup,
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(0, $lookup->calls);
    }

    public function testAGatewayThatCannotAnswerLeavesTheResultAlone(): void
    {
        // Degrading to the old behaviour, never to an empty reply: a shop whose gateway cannot do
        // this keeps showing the family, which is what it did before this filter existed.
        $result = (new UnbuyableFamilies())->apply(
            [$this->card('gloves', StockSource::Parent)],
            null,
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(['gloves'], self::ids($result['cards']));
    }
}
