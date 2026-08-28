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

    public function testIncludesReasonsWhenProvided(): void
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
        );

        $result = ToolProductSummary::of([$card], ['fx-001' => ['in_stock']]);

        self::assertSame(['in_stock'], $result[0]['reasons']);
    }

    public function testNoFigureEverReachesTheSummary(): void
    {
        // Mirrors TruncatedFamiliesTest::testNoFigureEverReachesTheSummary's approach: json-encode
        // the actual output and assert specific figures/fields are absent, rather than trusting the
        // code to be read. A card with every forbidden field populated, so a leak cannot hide behind
        // a null default.
        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: 'A jersey.',
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: '3-5 days',
            url: '/p/fx-001',
            imageUrl: 'https://example.test/fx-001.jpg',
            options: ['Colour' => 'Blue'],
            properties: ['Material' => ['Merino']],
        );

        $encoded = json_encode(ToolProductSummary::of([$card]), \JSON_THROW_ON_ERROR);

        foreach (['price', 'stock', 'deliveryTime', 'url', '54.9', '3-5 days'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, \sprintf('%s leaked', $forbidden));
        }
    }

    public function testOmitsReasonsKeyWhenNoneProvided(): void
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
        );

        $result = ToolProductSummary::of([$card]);

        self::assertArrayNotHasKey('reasons', $result[0] ?? []);
    }
}
