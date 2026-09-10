<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * A bundle's contents in the shape the model receives.
 *
 * **This is the one widening of the summary that removes a fabrication surface instead of opening
 * one.** The class's standing rule is "never widen this with a figure", and nothing here is one: an
 * item's name is the shop's own product name and `quantity` is `bundle_item.quantity`, the bundle's
 * composition rather than a price, a stock level or a cart amount. What it replaces is measured —
 * asked what was in a bundle, the assistant answered with a "Gear brush" no product in the
 * catalogue has ever been called and a "Chain lube 120 ml" that is 100 ml, and burned all five
 * tool calls searching for the rest.
 */
final class ToolProductSummaryBundleTest extends TestCase
{
    private const BUNDLE_ID = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    /**
     * @param list<BundleItem> $items
     */
    private function card(array $items): ProductCard
    {
        return new ProductCard(
            id: self::BUNDLE_ID,
            parentId: null,
            name: 'Roadside Repair Kit',
            description: null,
            price: 73.08,
            currency: 'EUR',
            stock: 19,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . self::BUNDLE_ID,
            imageUrl: null,
            bundleItems: $items,
        );
    }

    public function testTheContentsReachTheModelAsNamesInTheMerchantsOrder(): void
    {
        $summary = ToolProductSummary::of([$this->card([
            new BundleItem('Mini Pump 120psi', 1, true),
            new BundleItem('Tyre Lever Set', 1, true),
        ])]);

        self::assertSame([['name' => 'Mini Pump 120psi'], ['name' => 'Tyre Lever Set']], $summary[0]['bundle'] ?? null);
    }

    public function testAQuantityAboveOneIsStatedAndAQuantityOfOneIsNot(): void
    {
        // Same reasoning as ProductCard::$priceQuantity: one means "no amount worth stating", and
        // a key on every item would spend tokens restating the default. Two tubes, though, is the
        // difference between the kit covering one puncture and two.
        $summary = ToolProductSummary::of([$this->card([
            new BundleItem('Inner Tube Presta 700c', 2, true),
            new BundleItem('Multi-Tool 12', 1, true),
        ])]);

        self::assertSame(
            [['name' => 'Inner Tube Presta 700c', 'quantity' => 2], ['name' => 'Multi-Tool 12']],
            $summary[0]['bundle'] ?? null,
        );
    }

    public function testOnlyAnOptionalItemIsFlagged(): void
    {
        // **Only ever true, never false**, exactly as `soldOut` and `available` are: an absent key
        // means the item is part of the bundle as sold. The asymmetry is chosen by what each error
        // costs — telling a shopper they may drop an item the shop will charge them for is worse
        // than leaving an optional item unmarked.
        $summary = ToolProductSummary::of([$this->card([
            new BundleItem('Dry Chain Lube 100ml', 1, true),
            new BundleItem('Bike Wash 1L', 1, false),
        ])]);

        self::assertSame(
            [['name' => 'Dry Chain Lube 100ml'], ['name' => 'Bike Wash 1L', 'optional' => true]],
            $summary[0]['bundle'] ?? null,
        );
    }

    public function testAnOrdinaryProductGetsNoBundleKeyAtAll(): void
    {
        // Not an empty list: "this product has no contents" is an inference worth denying the
        // model, the same way a product with no description gets no `description` key.
        $summary = ToolProductSummary::of([$this->card([])]);
        $row = $summary[0] ?? [];

        self::assertArrayNotHasKey('bundle', $row);
    }
}
