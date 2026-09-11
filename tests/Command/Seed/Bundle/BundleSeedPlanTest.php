<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bundle;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bundle\BundleSeedPlan;
use Swag\AssistantStarterKit\Command\Seed\SeedTax;

/**
 * The payload a Commercial bundle needs, and the two rules that are not obvious from the schema.
 *
 * Four bundles were built on the staging shop by hand on 2026-09-10 and the two things that cost
 * time are both encoded here rather than in a comment somewhere:
 *
 * 1. **`min` must be at least 1 even for an optional item.** `min => 0` is rejected outright —
 *    *"Bundle … has an invalid minimum selection (0). It must be greater than zero."* What makes an
 *    item optional is `required => false`; `min` is the quantity if it is taken.
 * 2. **The bundle product's own price is forced to zero.** Mirrors Commercial's own
 *    `ProductBundlePayloadHydrator`: the sellable figure is derived from the items and the discount
 *    by `BundlePriceCalculator`, and anything authored here is ignored or, worse, believed.
 *
 * Ids are derived from the product number through {@see \Swag\AssistantStarterKit\Command\Seed\SeedId},
 * like every other id the seeders write. The hand-built script used `Uuid::randomHex()`, so every run
 * produced new ids and every note, trace or screenshot holding one went stale — which is the whole
 * reason this became a command.
 *
 * @phpstan-import-type BundlePayload from BundleSeedPlan
 */
final class BundleSeedPlanTest extends TestCase
{
    private const CHANNEL = '01a01edd80bc719a92184bed257121ce';
    private const CATEGORY = '7174a22b189766d2fa8275470859d6d5';

    /**
     * @return array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>}
     */
    private static function bundle(): array
    {
        return [
            'number' => 'bundle-roadside-repair',
            'name' => 'Roadside Repair Kit',
            'description' => 'Everything needed to fix a puncture at the roadside.',
            'category' => 'Maintenance',
            'discount' => ['type' => 'percentage', 'value' => 10.0],
            'items' => [
                ['number' => 'sk-108', 'quantity' => 1, 'required' => true],
                ['number' => 'bk-tube-presta-700c', 'quantity' => 2, 'required' => true],
                ['number' => 'bk-cleaner-bike-wash-1l', 'quantity' => 1, 'required' => false],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function memberIds(): array
    {
        return [
            'sk-108' => 'aa' . str_repeat('0', 30),
            'bk-tube-presta-700c' => 'bb' . str_repeat('0', 30),
            'bk-cleaner-bike-wash-1l' => 'cc' . str_repeat('0', 30),
        ];
    }

    /**
     * @return BundlePayload
     */
    private static function payload(): array
    {
        return BundleSeedPlan::payload(
            self::bundle(),
            self::memberIds(),
            new SeedTax('01a01edcb74671e8aaa09605e7ac44be', 19.0),
            self::CHANNEL,
            self::CATEGORY,
        );
    }

    public function testTheProductIsAGroupedBundleWithNoParentAndNoAuthoredPrice(): void
    {
        $payload = self::payload();

        self::assertSame('grouped_bundle', $payload['type']);
        self::assertNull($payload['parentId']);
        self::assertSame(0.0, $payload['price'][0]['gross']);
        self::assertSame(0.0, $payload['price'][0]['net']);
    }

    public function testEveryItemCarriesAMinimumOfAtLeastOneIncludingTheOptionalOne(): void
    {
        // The validator rejects `min => 0` outright, optional or not.
        foreach (self::payload()['bundleItems'] as $item) {
            self::assertGreaterThanOrEqual(1, $item['min'], 'min must be >= 1 for every item');
        }
    }

    public function testOptionalityIsCarriedByTheRequiredFlagAndNotByMin(): void
    {
        $items = self::payload()['bundleItems'];

        self::assertTrue($items[0]['required']);
        self::assertFalse($items[2]['required']);
        self::assertSame(1, $items[2]['min']);
    }

    public function testItemsKeepTheMerchantsOrderTheirQuantitiesAndTheirProductIds(): void
    {
        $items = self::payload()['bundleItems'];

        self::assertSame([1, 2, 3], array_column($items, 'position'));
        self::assertSame([1, 2, 1], array_column($items, 'quantity'));
        self::assertSame(array_values(self::memberIds()), array_column($items, 'productId'));
    }

    public function testTheDiscountIsActiveAndCarriesItsCurrency(): void
    {
        $discount = self::payload()['bundleDiscounts'][0];

        self::assertSame('percentage', $discount['type']);
        self::assertSame(10.0, $discount['value']);
        self::assertTrue($discount['active']);
        self::assertNotSame('', $discount['currencyId']);
    }

    public function testTheBundleIsVisibleInTheChannelAndFiledInItsCategory(): void
    {
        $payload = self::payload();

        self::assertSame(self::CHANNEL, $payload['visibilities'][0]['salesChannelId']);
        self::assertSame(self::CATEGORY, $payload['categories'][0]['id']);
    }

    public function testTheIdIsDerivedFromTheProductNumberSoAReRunCannotDouble(): void
    {
        // The hand-built script used Uuid::randomHex(), so every run produced four new bundles' worth
        // of ids and stale references everywhere. Same number in, same id out.
        self::assertSame(self::payload()['id'], self::payload()['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', self::payload()['id']);
    }
}
