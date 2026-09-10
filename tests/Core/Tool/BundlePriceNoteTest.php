<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\BundlePriceNote;

/**
 * What a bundle's quoted figure actually covers, stated rather than left to be derived.
 *
 * **Measured against a live bundle on 2026-09-10.** The Drivetrain Care Bundle quotes €73.57, and
 * that is 12.90 + 14.90 + 24.00 for its three required items **plus 14.90 + 16.90 for two optional
 * ones**, less 12% — 83.60 → 73.57. Its stock, 14, is derived from the required items alone. So
 * Commercial's two derived numbers disagree about what "the bundle" is, and the figure the card
 * carries is the **maximum** a shopper might pay: declining both optional items puts it at 45.58.
 * Nothing on the card said so, so €73.57 was presented as the price.
 *
 * The idiom is {@see \Swag\AssistantStarterKit\Core\Tool\EverythingShown}'s, and so is the reason:
 * asking the model to work out what a price covers by cross-referencing an `optional` flag against
 * a figure is the arithmetic the September 2026 review measured it getting wrong, so the shop states
 * the fact instead.
 *
 * **Only when there is something to get wrong.** A bundle whose every item is required has an
 * unambiguous price, and a note there is dead weight on the turn and one more sentence inviting the
 * model to qualify a figure that needs no qualifying.
 */
final class BundlePriceNoteTest extends TestCase
{
    /**
     * @param list<BundleItem> $items
     */
    private function card(array $items, string $name = 'Drivetrain Care Bundle'): ProductCard
    {
        return new ProductCard(
            id: 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0',
            parentId: null,
            name: $name,
            description: null,
            price: 73.57,
            currency: 'EUR',
            stock: 14,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0',
            imageUrl: null,
            bundleItems: $items,
        );
    }

    public function testABundleWithAnOptionalItemSaysWhatThePriceCovers(): void
    {
        $reply = BundlePriceNote::replyFor([
            $this->card([
                new BundleItem('Dry Chain Lube 100ml', 1, true),
                new BundleItem('Bike Wash 1L', 1, false),
            ]),
        ]);

        self::assertArrayHasKey('bundle_note', $reply);
        self::assertStringContainsString('optional', $reply['bundle_note'] ?? '');
    }

    /**
     * **Revised on live evidence, and the first version of this class was wrong.** It sent a note
     * only when a bundle had optional items, on the grounds that an all-required bundle's price is
     * unambiguous. Then the deployed assistant was asked *"What is in the Drivetrain Care Bundle and
     * how much do I save?"* and spent four of its five tool calls searching for `Cassette Removal
     * Tool`, `Chain Wear Indicator`, `Dry Chain Lube 100ml` and `Bike Wash 1L` — pricing the items
     * one by one to work out the saving — and the turn ended in `tool_limit_exceeded` with one
     * unrelated card. The saving is what a shopper asks about a bundle, every bundle invites the
     * question, and the sum of its items minus its price is a figure Shopware never calculated.
     */
    public function testEveryBundleIsToldTheSavingIsNotToBeComputedFromItsItems(): void
    {
        $reply = BundlePriceNote::replyFor([
            $this->card([
                new BundleItem('Mini Pump 120psi', 1, true),
                new BundleItem('Tyre Lever Set', 1, true),
            ], 'Roadside Repair Kit'),
        ]);

        self::assertArrayHasKey('bundle_note', $reply);
        self::assertStringNotContainsString('optional', $reply['bundle_note'] ?? '');
    }

    public function testAnOrdinaryProductGetsNoNote(): void
    {
        self::assertSame([], BundlePriceNote::replyFor([$this->card([], 'Multi-Tool 12')]));
    }

    public function testOneOptionalItemAnywhereInTheResultIsEnough(): void
    {
        // The note is about the result rather than one card, exactly as `all_shown_note` is: a
        // reply carrying several bundles needs the caveat if any of them can be trimmed.
        $reply = BundlePriceNote::replyFor([
            $this->card([new BundleItem('Tyre Lever Set', 1, true)], 'Roadside Repair Kit'),
            $this->card([new BundleItem('Bike Wash 1L', 1, false)]),
        ]);

        self::assertArrayHasKey('bundle_note', $reply);
    }

    public function testTheNoteNeverQuotesAFigureItself(): void
    {
        // The one thing this must not do. What the shopper would pay having declined an optional
        // item is a price Shopware did not calculate, and summing the remaining items here would
        // be the fabrication the whole pipeline exists to prevent.
        $reply = BundlePriceNote::replyFor([
            $this->card([
                new BundleItem('Dry Chain Lube 100ml', 1, true),
                new BundleItem('Bike Wash 1L', 1, false),
            ]),
        ]);

        self::assertDoesNotMatchRegularExpression('/\d/', $reply['bundle_note'] ?? '');
    }
}
