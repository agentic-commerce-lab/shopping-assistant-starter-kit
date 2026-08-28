<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\MatchReasons;

final class MatchReasonsTest extends TestCase
{
    private function card(string $id, int $stock): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: null,
            name: 'Product ' . $id,
            description: null,
            price: 10.0,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/' . $id,
            imageUrl: null,
        );
    }

    public function testAttributesTheFirstMatchingTermWhenMoreThanOneTermWasSearched(): void
    {
        $dress = $this->card('fx-dress', 5);
        $suit = $this->card('fx-suit', 5);

        $reasons = MatchReasons::of([$dress, $suit], [
            'occasion dress' => [$dress],
            'occasion suit' => [$suit],
        ]);

        self::assertContains('matched_term:occasion dress', $reasons['fx-dress'] ?? []);
        self::assertContains('matched_term:occasion suit', $reasons['fx-suit'] ?? []);
    }

    public function testAddsNoTermReasonForASingleTermSearch(): void
    {
        $card = $this->card('fx-001', 5);

        $reasons = MatchReasons::of([$card], ['jersey' => [$card]]);

        self::assertNotContains('matched_term:jersey', $reasons['fx-001'] ?? []);
    }

    public function testFlagsInStockProducts(): void
    {
        $inStock = $this->card('fx-in', 5);
        $outOfStock = $this->card('fx-out', 0);

        $reasons = MatchReasons::of([$inStock, $outOfStock], []);

        self::assertContains('in_stock', $reasons['fx-in'] ?? []);
        self::assertNotContains('in_stock', $reasons['fx-out'] ?? []);
    }

    public function testFlagsTheOnlyMatchWhenExactlyOneProductWasReturned(): void
    {
        $card = $this->card('fx-001', 5);

        $reasons = MatchReasons::of([$card], []);

        self::assertContains('only_match', $reasons['fx-001'] ?? []);
    }
}
