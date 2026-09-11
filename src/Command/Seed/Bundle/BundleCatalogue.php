<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bundle;

/**
 * The bundles this shop sells, as sets a cyclist would actually buy together.
 *
 * **Every member is a single-variant product on purpose, and that is a hard constraint rather than
 * a preference.** Commercial's `BundleAvailableFilter` excludes any bundle holding an item with
 * `childCount > 1` and no parent — *"for first iteration, we need to filter out non single variant
 * items"* — and the exclusion is total and silent: measured 2026-09-10, a bundle built on `bk-chain`
 * (3 variants) returned **404 on its own product page** and was absent from every search, which
 * looks exactly like a broken plugin. {@see SingleVariantMembers} checks it before anything is
 * written, so the failure arrives as a sentence rather than as a missing product.
 *
 * The four cover the cases worth having in a demo shop, not just four plausible sets:
 *
 * - **Roadside Repair Kit** — a plain percentage discount, and one member at quantity two, so
 *   "two inner tubes" has to survive the whole pipeline.
 * - **Commuter Safety Set** — an **absolute** discount, which is stored per currency and is the
 *   case a percentage cannot exercise.
 * - **Tubeless Conversion Kit** — contains `sk-110`, which is out of stock, so the derived bundle
 *   stock is 0 and the whole set reports sold out. That is the availability-honesty case.
 * - **Drivetrain Care Bundle** — two **optional** members, so the price covers items the shopper may
 *   decline while the stock counts only the required ones.
 */
final class BundleCatalogue
{
    /**
     * Commercial's product type for a bundle. A plain string rather than its `ProductBundleType`
     * constant: `shopware/commercial` is not a dependency of this plugin.
     */
    public const PRODUCT_TYPE = 'grouped_bundle';

    private function __construct() {}

    /**
     * @return list<array{number: string, name: string, description: string, category: string, discount: array{type: string, value: float}, items: list<array{number: string, quantity: int, required: bool}>}>
     */
    public static function all(): array
    {
        return [
            [
                'number' => 'bundle-roadside-repair',
                'name' => 'Roadside Repair Kit',
                'description' =>
                    'Everything needed to fix a puncture at the roadside instead of walking home. '
                        . 'Packs into a jersey pocket or a small saddle bag.',
                'category' => 'Maintenance',
                'discount' => ['type' => 'percentage', 'value' => 10.0],
                'items' => [
                    ['number' => 'sk-108', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-tool-tyre-levers', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-tube-presta-700c', 'quantity' => 2, 'required' => true],
                    ['number' => 'sk-107', 'quantity' => 1, 'required' => true],
                ],
            ],
            [
                'number' => 'bundle-commuter-safety',
                'name' => 'Commuter Safety Set',
                'description' =>
                    'A lit and locked commute in a single order. Put together for riders who leave '
                        . 'the bike at a station or outside an office through the working day.',
                'category' => 'Lights',
                'discount' => ['type' => 'absolute', 'value' => 15.0],
                'items' => [
                    ['number' => 'sk-102', 'quantity' => 1, 'required' => true],
                    ['number' => 'sk-103', 'quantity' => 1, 'required' => true],
                    ['number' => 'sk-104', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-lock-cable-combination', 'quantity' => 1, 'required' => true],
                ],
            ],
            [
                'number' => 'bundle-tubeless-conversion',
                'name' => 'Tubeless Conversion Kit',
                'description' =>
                    'Converts one tubeless-ready 700c wheelset to run without inner tubes. '
                        . 'Covers a single wheelset; tyres are not part of this set.',
                'category' => 'Tyres',
                'discount' => ['type' => 'percentage', 'value' => 8.0],
                'items' => [
                    ['number' => 'bk-tubeless-rim-tape', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-tubeless-valve-set', 'quantity' => 1, 'required' => true],
                    // Out of stock, which is the point: the derived bundle stock becomes 0.
                    ['number' => 'sk-110', 'quantity' => 1, 'required' => true],
                ],
            ],
            [
                'number' => 'bundle-drivetrain-service',
                'name' => 'Drivetrain Care Bundle',
                'description' =>
                    'Keeps a drivetrain clean and says when the chain is finished, sized for a '
                        . 'single 11-speed service. The wear indicator and the bike wash are optional additions.',
                'category' => 'Drivetrain',
                'discount' => ['type' => 'percentage', 'value' => 12.0],
                'items' => [
                    ['number' => 'bk-lube-dry-100', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-cleaner-degreaser-500', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-tool-cassette-remover', 'quantity' => 1, 'required' => true],
                    ['number' => 'bk-chain-wear-indicator', 'quantity' => 1, 'required' => false],
                    ['number' => 'bk-cleaner-bike-wash-1l', 'quantity' => 1, 'required' => false],
                ],
            ],
        ];
    }

    /**
     * Every member product number the catalogue references, deduplicated — what the command has to
     * resolve against the shop before it can write anything.
     *
     * @return list<string>
     */
    public static function memberNumbers(): array
    {
        $numbers = [];

        foreach (self::all() as $bundle) {
            foreach ($bundle['items'] as $item) {
                $numbers[$item['number']] = true;
            }
        }

        return array_keys($numbers);
    }
}
