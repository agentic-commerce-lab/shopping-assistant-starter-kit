<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bundle;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Swag\AssistantStarterKit\Command\Seed\SeedId;
use Swag\AssistantStarterKit\Command\Seed\SeedTax;

/**
 * One Commercial bundle as a DAL payload.
 *
 * **Mirrors Commercial's own `ProductBundlePayloadHydrator` where it matters**, because the Admin
 * API route that would apply it is gated on the Routes licence (`PRODUCT_BUNDLE-1159113`) and a
 * seeder writes through the DAL instead. Two of its rules are not visible in the schema and both
 * cost time to find by hand on 2026-09-10:
 *
 * 1. **`min` must be at least 1 for every item, optional ones included.** `min => 0` is rejected —
 *    *"Bundle … has an invalid minimum selection (0). It must be greater than zero."* Optionality is
 *    carried by `required => false`; `min` is the quantity if the shopper takes it.
 * 2. **The bundle product's own price is forced to zero.** The sellable figure is
 *    Σ(item price × quantity) − discount, computed by `BundlePriceCalculator` at runtime and
 *    persisted into `cheapest_price_accessor`. Authoring a price here would be authoring a number
 *    the shop then contradicts.
 *
 * The id comes from {@see SeedId}, like every other id these seeders write, so **a re-run updates
 * rather than doubles**. The hand-built script used `Uuid::randomHex()`: every run produced four new
 * bundles and left every trace, note and screenshot pointing at ids that no longer existed.
 *
 * @phpstan-type BundlePayload array{     id: string,     productNumber: string,     type: string,     parentId: null,     price: list<array{currencyId: string, net: float, gross: float, linked: bool}>,     taxId: string,     stock: int,     active: bool,     name: string,     description: string,     bundleItems: list<array{id: string, productId: string, quantity: int, position: int, required: bool, min: int, max: null, showBundleOnItemPdp: bool}>,     bundleDiscounts: list<array{id: string, type: string, value: float, active: bool, currencyId: string, preventCombination: bool}>,     visibilities: list<array{id: string, salesChannelId: string, visibility: int}>,     categories: list<array{id: string}> }
 *
 * Stock is written as zero and left alone afterwards. Commercial derives it —
 * `min(floor(item.stock / item.quantity))` over the **required** items — and `BundleStockUpdateService`
 * is the only sanctioned writer, so a seeded figure would be overwritten at best.
 */
final readonly class BundleSeedPlan
{
    /**
     * The namespace every bundle id is derived under. Changing it renames every bundle this seeder
     * has ever written, which is a doubling rather than an update.
     */
    public const ID_NAMESPACE = 'assistant-bundle';

    public static function idFor(string $productNumber): string
    {
        return SeedId::forPath(self::ID_NAMESPACE, $productNumber);
    }

    /**
     * @param array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>} $bundle
     * @param array<string, string> $memberIds product number => the member product's id
     *
     * @return BundlePayload
     */
    public static function payload(
        array $bundle,
        array $memberIds,
        SeedTax $tax,
        string $salesChannelId,
        string $categoryId,
    ): array {
        return [
            'id' => self::idFor($bundle['number']),
            'productNumber' => $bundle['number'],
            'type' => BundleCatalogue::PRODUCT_TYPE,
            'parentId' => null,
            // Forced to zero, exactly as the hydrator does — see the class docblock.
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'net' => 0.0,
                'gross' => 0.0,
                'linked' => true,
            ]],
            'taxId' => $tax->id,
            // Derived by Commercial from the required items; see the class docblock.
            'stock' => 0,
            'active' => true,
            'name' => $bundle['name'],
            'description' => $bundle['description'],
            'bundleItems' => self::items($bundle['items'], $memberIds),
            'bundleDiscounts' => [[
                'id' => SeedId::forPath(self::ID_NAMESPACE . '-discount', $bundle['number']),
                'type' => $bundle['discount']['type'],
                'value' => $bundle['discount']['value'],
                'active' => true,
                // Named rather than left null: a bundle discount is stored per currency, and an
                // absolute one means nothing without saying which.
                'currencyId' => Defaults::CURRENCY,
                'preventCombination' => false,
            ]],
            'visibilities' => [[
                'id' => SeedId::forPath(self::ID_NAMESPACE . '-visibility', $bundle['number']),
                'salesChannelId' => $salesChannelId,
                'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
            ]],
            'categories' => [['id' => $categoryId]],
        ];
    }

    /**
     * @param list<array{number: string, quantity: int, required: bool}> $items
     * @param array<string, string> $memberIds
     *
     * @return list<array{id: string, productId: string, quantity: int, position: int, required: bool, max: null, min: int, showBundleOnItemPdp: bool}>
     */
    private static function items(array $items, array $memberIds): array
    {
        $rows = [];
        $position = 1;

        foreach ($items as $item) {
            $rows[] = [
                'id' => SeedId::forPath(self::ID_NAMESPACE . '-item', $item['number'] . '@' . $position),
                'productId' => $memberIds[$item['number']] ?? '',
                'quantity' => $item['quantity'],
                'position' => $position,
                'required' => $item['required'],
                // Never 0, whatever `required` says — see the class docblock.
                'min' => 1,
                'max' => null,
                'showBundleOnItemPdp' => true,
            ];

            ++$position;
        }

        return $rows;
    }
}
