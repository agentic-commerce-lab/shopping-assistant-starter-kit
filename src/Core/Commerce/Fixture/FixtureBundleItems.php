<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;

/**
 * A fixture product's bundle members, so bundle behaviour is reachable without Shopware Commercial.
 *
 * Bundles are a licensed feature of a plugin this one does not depend on, so without a fixture
 * shape for them the only way to exercise a bundle answer is a live Evolve-tier shop — which is how
 * a feature ends up with no test at all. This is what lets the journey and eval suites reach it.
 *
 * **Standalone products only**, mirroring the real constraint rather than being lenient about it: a
 * Commercial bundle is `type = grouped_bundle` with no parent and cannot itself be a variant, and
 * its members must be single-variant products or `BundleAvailableFilter` hides the whole bundle
 * shop-wide — 404 on its own product page and absent from every search, measured 2026-09-10.
 *
 * Defaults match the database: `bundle_item.quantity` and `bundle_item.required` are both
 * `NOT NULL DEFAULT 1`, and *required* is the safe direction for a flag that decides whether a
 * shopper may decline an item the shop would otherwise charge for.
 *
 * Split from {@see FixtureIndex} because that class was already at the complexity gate, and the
 * standing constraints answer a complexity finding with a split rather than a suppression.
 */
final readonly class FixtureBundleItems
{
    /**
     * @param list<array{name: string, quantity?: int, required?: bool}> $items
     *
     * @return list<BundleItem>
     */
    public static function of(array $items): array
    {
        return array_map(
            static fn(array $item): BundleItem => new BundleItem(
                name: $item['name'],
                quantity: max(1, $item['quantity'] ?? 1),
                required: $item['required'] ?? true,
            ),
            $items,
        );
    }
}
