<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

final class ToolProductSummaryTest extends TestCase
{
    public function testIncludesBoundedProperties(): void
    {
        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: 'A jersey.',
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            options: ['Colour' => 'Blue'],
            properties: ['Material' => ['Merino', 'Nylon']],
        );

        $result = ToolProductSummary::of([$card]);

        self::assertSame(['Material' => ['Merino', 'Nylon']], $result[0]['properties']);
    }

    public function testAnEmptyPropertyListStaysEmpty(): void
    {
        $card = new ProductCard(
            id: 'fx-002',
            parentId: null,
            name: 'Plain Bottle',
            description: null,
            price: 9.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-002',
            imageUrl: null,
        );

        $result = ToolProductSummary::of([$card]);

        self::assertSame([], $result[0]['properties']);
    }
}
