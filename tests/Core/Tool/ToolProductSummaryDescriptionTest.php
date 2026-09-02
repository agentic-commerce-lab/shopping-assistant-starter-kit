<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * The comparison path's wider shape, and the search path's unchanged one.
 *
 * **The first test here is the important one.** `search_products`' minimal shape exists for a measured
 * reason — with opaque results, identifying one of N candidates cost N tool calls and exhausted the
 * budget — and description text in every search result would reopen the token half of that. So the
 * contract is: `of()` is byte-for-byte what it always was, and only a caller that asks for
 * descriptions gets them.
 */
final class ToolProductSummaryDescriptionTest extends TestCase
{
    public function testTheSearchPathShapeIsUnchangedAndCarriesNoDescription(): void
    {
        $summary = ToolProductSummary::of([self::card('A helmet with an extended rear shell.')]);

        // `available` since 2026-09-02: this card is a sellable unit with stock 3, so it carries the
        // buyability mark. See ToolProductSummaryAvailabilityTest.
        self::assertSame(['id', 'name', 'options', 'properties', 'available'], array_keys($summary[0] ?? []));
    }

    public function testTheComparisonPathAddsTheDescriptionAsPlainProse(): void
    {
        $summary = ToolProductSummary::withDescriptions([self::card('<p>An extended rear shell.</p>')]);

        self::assertSame('An extended rear shell.', $summary[0]['description'] ?? null);
    }

    /**
     * A product with no description gets no key rather than an empty one. An empty string is a value
     * the model can reason about — "the shop says nothing about this" invites exactly the inference
     * this pipeline exists to prevent — while an absent key is simply absent.
     */
    public function testAProductWithNothingToSayGetsNoDescriptionKey(): void
    {
        foreach ([null, '', '<p>&nbsp;</p>'] as $empty) {
            $summary = ToolProductSummary::withDescriptions([self::card($empty)]);

            self::assertArrayNotHasKey('description', $summary[0] ?? [], var_export($empty, true));
        }
    }

    public function testTheDescriptionDoesNotDisplaceTheFieldsTheModelAlreadyRelieson(): void
    {
        $summary = ToolProductSummary::withDescriptions([self::card('Something.')]);

        self::assertSame(
            ['id', 'name', 'options', 'properties', 'available', 'description'],
            array_keys($summary[0] ?? []),
        );
    }

    private static function card(?string $description): ProductCard
    {
        return new ProductCard(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            parentId: null,
            name: 'Trail Helmet',
            description: $description,
            price: 79.0,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/trail-helmet',
            imageUrl: null,
            options: ['Size' => 'M'],
            properties: ['Terrain' => ['Trail']],
        );
    }
}
