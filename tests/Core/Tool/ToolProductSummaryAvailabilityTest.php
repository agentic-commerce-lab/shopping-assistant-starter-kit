<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * The positive buyability mark, and the two guards that keep its opposite's reasoning intact.
 *
 * ## Why this reverses part of a deliberate decision
 *
 * {@see ToolProductSummarySoldOutTest} argued the asymmetry: only ever `soldOut: true`, never
 * `soldOut: false`, so the model could learn that something is *not* purchasable and never that it
 * is. A wrong "sold out" loses a sale; a wrong "in stock" is a promise the shop then breaks.
 *
 * That reasoning is right about the cost of the two errors and wrong about what the ban bought.
 * Measured live on the staging shop, 2026-09-02 — asked *"ist das auf Lager?"* about a product whose
 * card read `stock=7`, the assistant answered:
 *
 * > *"Ich habe das Club Jersey in Red, Größe XL gefunden. Der Shop zeigt die aktuellen Angaben zur
 * > Verfügbarkeit direkt für dich an."*
 *
 * That is a non-answer to the most ordinary question in commerce, and it was the only answer the
 * rules allowed. Meanwhile the card beside it stated the availability plainly — so the shop was
 * already making the claim the model was forbidden to make, and the shopper simply had to read it
 * for themselves.
 *
 * ## What keeps the promise from being broken
 *
 * - **A boolean, never a quantity.** No count, no threshold, no "low stock" — the prompt still
 *   forbids quoting the number, so no figure is earned here that was not earned before.
 * - **Never a family parent.** A parent's stock is the family's aggregate, so "available" would be a
 *   claim about a unit nobody has chosen yet. Such a product carries NEITHER mark, and the prompt
 *   spells that third state out as unknown.
 * - **Sold-out products are untouched.** The mark is the same figure read the other way, so it cannot
 *   appear for them.
 * - **The audit still measures the sentence.** `unbackedAvailabilityInProse()` compares an
 *   availability claim against the RENDERED cards, so a claim about a product the shop did not put on
 *   screen is still flagged.
 */
final class ToolProductSummaryAvailabilityTest extends TestCase
{
    public function testASellableUnitInStockIsMarkedAvailable(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 7, source: StockSource::Product)]);

        self::assertTrue($summary[0]['available'] ?? null);
        self::assertArrayNotHasKey('soldOut', $summary[0] ?? []);
    }

    public function testASoldOutProductIsNotMarkedAvailable(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 0, source: StockSource::Product)]);

        self::assertArrayNotHasKey('available', $summary[0] ?? []);
        self::assertTrue($summary[0]['soldOut'] ?? null);
    }

    /**
     * The family case, and the reason this mark is narrower than its opposite: the stock on a parent
     * is the family's total, so it says nothing about any variant a shopper could actually buy.
     */
    public function testAFamilyParentCarriesNeitherMarkHoweverMuchStockItReports(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 35, source: StockSource::Parent)]);

        self::assertArrayNotHasKey('available', $summary[0] ?? []);
        self::assertArrayNotHasKey('soldOut', $summary[0] ?? []);
    }

    /** No count reaches the model under any name — the mark is a yes, not a figure. */
    public function testTheMarkCarriesNoQuantity(): void
    {
        $summary = ToolProductSummary::of([self::card(stock: 7, source: StockSource::Product)]);

        self::assertSame(true, $summary[0]['available']);
        self::assertStringNotContainsString('7', json_encode(array_diff_key($summary[0], ['id' => null])) ?: '');
    }

    private static function card(int $stock, StockSource $source): ProductCard
    {
        return new ProductCard(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            parentId: $source === StockSource::Parent ? null : 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            name: 'Club Jersey',
            description: null,
            price: 59.0,
            currency: 'EUR',
            stock: $stock,
            stockSource: $source,
            deliveryTime: null,
            url: '/p/club-jersey',
            imageUrl: null,
            options: ['Colour' => 'Red', 'Size' => 'XL'],
        );
    }
}
