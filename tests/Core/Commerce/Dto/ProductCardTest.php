<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

final class ProductCardTest extends TestCase
{
    public function testVariantCardReportsVariantStockSource(): void
    {
        $card = new ProductCard(
            id: 'fx-026-blue-m',
            parentId: 'fx-026',
            name: 'Trail Jersey',
            description: 'Lightweight merino trail jersey.',
            price: 49.90,
            currency: 'EUR',
            stock: 0,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/fx-026-blue-m',
            imageUrl: null,
            options: ['Colour' => 'Blue', 'Size' => 'M'],
            categoryPath: ['Apparel', 'Jerseys'],
            properties: ['Colour' => ['Blue'], 'Size' => ['M']],
        );

        self::assertSame(StockSource::Variant, $card->stockSource);
        self::assertSame(0, $card->stock);
        self::assertSame('fx-026', $card->parentId);
    }
}
