<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * The one thing the model is now told about buyability — and only in one direction.
 *
 * **Why this changes D4 at all.** Measured live 2026-09-01: asked for the Long Finger Gloves with
 * *"I want to order them today"*, the assistant replied *"would you like me to add a pair to your
 * cart?"* while the card beside it read **Out of stock** and its button was disabled. It claimed no
 * availability — the audit was satisfied — but it offered something that cannot happen, because D4
 * deliberately withheld stock and the model had no way to know.
 *
 * **The asymmetry is the design, not a compromise.** There is only ever `soldOut: true`; there is no
 * `soldOut: false`. An absent key means what it always meant — the model has been told nothing about
 * buyability and may not claim any. So this cannot become a route to "yes, we have it": the model can
 * only learn that something is *not* purchasable.
 *
 * That direction is chosen by what the two errors cost. A wrong "sold out" loses a sale the shop could
 * have made. A wrong "in stock" is a promise the shop then breaks, which is the failure D4 exists to
 * prevent, and it stays impossible here.
 */
final class ToolProductSummarySoldOutTest extends TestCase
{
    public function testAProductWithNoStockIsMarkedSoldOut(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 0)]);

        self::assertTrue($summary[0]['soldOut'] ?? null);
    }

    /**
     * **The key is absent, not false.** A `false` would be a statement about buyability, and the model
     * would be entitled to repeat it as "this is available" — the one claim the shop must render itself.
     */
    public function testAProductWithStockCarriesNoKeyAtAllRatherThanFalse(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 5)]);

        self::assertArrayNotHasKey('soldOut', $summary[0] ?? []);
    }

    /**
     * It reaches every tool, not only the comparison path. A shopper is just as badly served by being
     * offered a sold-out product out of a search as out of a comparison, and a boolean costs nothing —
     * unlike a description, whose exclusion from `search_products` has its own measured reason.
     */
    public function testTheComparisonPathCarriesItToo(): void
    {
        $summary = ToolProductSummary::withDescriptions([self::card(stock: 0)]);

        self::assertTrue($summary[0]['soldOut'] ?? null);
    }

    /**
     * The flag is exactly `true`, never a count and never a truthy stand-in for one.
     *
     * A loop asserting "no value is an integer" was the first version; the analyzer rejected it as an
     * impossible comparison, which is the stronger statement — the declared shape already makes a
     * quantity unrepresentable. So this asserts the part types cannot: that the value is the boolean
     * `true` rather than, say, a remaining-stock number that happens to be truthy.
     */
    public function testTheFlagIsExactlyTrueAndNeverAQuantity(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 0)]);

        self::assertSame(true, $summary[0]['soldOut'] ?? null);
    }

    private static function card(int $stock): ProductCard
    {
        return new ProductCard(
            id: str_pad((string) $stock, 32, 'a'),
            parentId: null,
            name: 'Long Finger Gloves',
            description: 'A light full-finger glove.',
            price: 29.9,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/gloves',
            imageUrl: null,
            options: ['Size' => 'M'],
            properties: ['Season' => ['Shoulder season']],
        );
    }
}
