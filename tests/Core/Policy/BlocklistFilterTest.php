<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;

final class BlocklistFilterTest extends TestCase
{
    private function card(string $id, string $category = 'Accessories'): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Item ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Parent,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            categoryPath: [$category],
        );
    }

    public function testRemovesBlockedProductIdsAndReportsThem(): void
    {
        $result = (new BlocklistFilter())->apply([
            $this->card('fx-014'),
            $this->card('fx-017'),
        ], new CatalogScope(blockedProductIds: ['fx-014']));

        self::assertSame(['fx-017'], array_map(static fn($c) => $c->id, $result['cards']));
        self::assertSame(['fx-014'], $result['removed']);
    }

    public function testRemovesCardsInBlockedCategories(): void
    {
        $result = (new BlocklistFilter())->apply([
            $this->card('fx-014', 'Restricted'),
            $this->card('fx-017'),
        ], new CatalogScope(blockedCategoryIds: ['Restricted']));

        self::assertCount(1, $result['cards']);
        self::assertSame(['fx-014'], $result['removed']);
    }

    public function testPassesEverythingThroughWhenNothingIsBlocked(): void
    {
        $result = (new BlocklistFilter())->apply([$this->card('fx-017')], new CatalogScope());

        self::assertCount(1, $result['cards']);
        self::assertSame([], $result['removed']);
    }
}
