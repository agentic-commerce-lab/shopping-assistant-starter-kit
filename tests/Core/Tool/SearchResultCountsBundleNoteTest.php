<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\BundlePriceNote;
use Swag\AssistantStarterKit\Core\Tool\SearchResultCounts;
use Swag\AssistantStarterKit\Tests\Support\BuildsProductCards;

/**
 * That the bundle caveat actually reaches a search result.
 *
 * Asserted separately from {@see BundlePriceNoteTest} because wiring is where this class of feature
 * dies quietly — the repo has already had a detector that computed the right answer and a
 * `logTraces` that was a no-op in production, and neither showed up in a unit test of the thing
 * itself. The note is keyed off `$returned`, the cards the shopper will actually see, rather than
 * the survivors: a bundle held back by narrowing is not one the reply quotes a price for.
 */
final class SearchResultCountsBundleNoteTest extends TestCase
{
    use BuildsProductCards;

    private static function bundleCard(bool $withOptional): ProductCard
    {
        return new ProductCard(
            id: 'bundle',
            parentId: null,
            name: 'Drivetrain Care Bundle',
            description: null,
            price: 73.57,
            currency: 'EUR',
            stock: 14,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/bundle',
            imageUrl: null,
            bundleItems: [
                new BundleItem('Dry Chain Lube 100ml', 1, true),
                new BundleItem('Bike Wash 1L', 1, !$withOptional),
            ],
        );
    }

    public function testASearchReturningABundleWithOptionalItemsCarriesTheCaveat(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: [self::bundleCard(true)],
            survivors: [self::bundleCard(true)],
            saturated: false,
        );

        self::assertSame(BundlePriceNote::NOTE . ' ' . BundlePriceNote::OPTIONAL_ITEMS, $counts['bundle_note'] ?? null);
    }

    public function testAnOrdinarySearchCarriesNoBundleFieldAtAll(): void
    {
        // Same reasoning as `withheld` and `all_shown`: a field present on every reply is context
        // paid for on the turns where it means nothing.
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::standalone(3),
            survivors: self::standalone(3),
            saturated: false,
        );

        self::assertArrayNotHasKey('bundle_note', $counts);
    }

    public function testABundleWithoutOptionalItemsStillCarriesTheSavingCaveat(): void
    {
        // See BundlePriceNoteTest: every bundle invites "how much do I save", and answering it by
        // pricing the items one at a time is what exhausted a live turn's tool budget.
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: [self::bundleCard(false)],
            survivors: [self::bundleCard(false)],
            saturated: false,
        );

        self::assertArrayHasKey('bundle_note', $counts);
    }
}
