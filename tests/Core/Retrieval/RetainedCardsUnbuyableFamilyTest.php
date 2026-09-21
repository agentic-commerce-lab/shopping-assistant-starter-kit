<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Retrieval\RetainedCards;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\RecordingFamilyLookup;

/**
 * The staging defect, at the layer that fixes it: a family whose every variant is sold out must not
 * survive a search when the merchant asked for sold-out products to be left out.
 *
 * Here rather than in the gateway because the answer needs the family's CHILDREN, and here rather
 * than in the tool because this is where every card list a retrieval can return already passes
 * through — all four of {@see \Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass}'s reads.
 */
final class RetainedCardsUnbuyableFamilyTest extends TestCase
{
    private function card(string $id, StockSource $source, int $stock): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $source === StockSource::Variant ? 'p' : null,
            name: 'Long Finger Gloves',
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

    public function testTheFamilyNobodyCanBuyASizeOfDoesNotReachTheReply(): void
    {
        $retained = RetainedCards::of(
            [$this->card('winter', StockSource::Variant, 1), $this->card('gloves', StockSource::Parent, 0)],
            new ProductQuery(term: 'Long Finger Gloves'),
            'Long Finger Gloves',
            new TraceRecorder(),
            new RecordingFamilyLookup([]),
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(['winter'], self::ids($retained->cards));
    }

    public function testAFamilyWithOneSizeLeftIsUntouched(): void
    {
        $retained = RetainedCards::of(
            [$this->card('gloves', StockSource::Parent, 0)],
            new ProductQuery(term: 'Long Finger Gloves'),
            'Long Finger Gloves',
            new TraceRecorder(),
            new RecordingFamilyLookup(['gloves']),
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(['gloves'], self::ids($retained->cards));
    }

    public function testAnEmptyAttemptNeverReachesTheShopForAnAnswer(): void
    {
        // RetrievalPass runs four reads; the three relaxations only run after an empty one. Without
        // this, a turn that relaxes twice would pay for three family lookups over nothing.
        $lookup = new RecordingFamilyLookup([]);

        RetainedCards::of(
            [],
            new ProductQuery(term: 'nothing'),
            'nothing',
            new TraceRecorder(),
            $lookup,
            new CatalogScope(hideOutOfStock: true),
        );

        self::assertSame(0, $lookup->calls);
    }

    public function testCallersThatPassNoLookupKeepTheBehaviourTheyHad(): void
    {
        $retained = RetainedCards::of(
            [$this->card('gloves', StockSource::Parent, 0)],
            new ProductQuery(term: 'Long Finger Gloves'),
            'Long Finger Gloves',
            new TraceRecorder(),
        );

        self::assertSame(['gloves'], self::ids($retained->cards));
    }
}
