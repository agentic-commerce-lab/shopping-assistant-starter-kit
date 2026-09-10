<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;

/**
 * Reads a Shopware Commercial bundle's composition off a product, **without naming a single
 * Commercial class.**
 *
 * `shopware/commercial` is not a dependency of this plugin (`composer.json` requires
 * `shopware/core` and `shopware/storefront` and nothing else), so `BundleItemEntity` and
 * `BundleItemCollection` cannot be imported here even though they are what the DAL actually
 * hydrates. Commercial registers `bundleItems` through an `EntityExtension`, which lands in the
 * entity's `extensions` bag, so the contract this class relies on is the smallest one that holds:
 * an iterable of {@see Entity} answering to `has()`/`get()` for `quantity`, `required` and
 * `product`. That is a documented core contract, not a Commercial detail, and it is why
 * `DalBundleItemsTest` can express the same shape with `ArrayEntity`.
 *
 * **Every read is guarded, and a member it cannot resolve is skipped rather than guessed at.** A
 * bundle whose item rows are half-loaded must degrade to a shorter list, never to an item with an
 * empty name or an invented quantity — an incomplete contents list is a smaller lie than a wrong
 * one, and the same rulings (R48, R49) that stop a partially hydrated product from ending a
 * shopper's turn apply to a partially hydrated bundle.
 *
 * **Defaults match the database, not convenience.** `bundle_item.quantity` and
 * `bundle_item.required` are both `NOT NULL` with defaults of `1` and `1`, so an unreadable flag
 * falls back to *required* — the direction that cannot tell a shopper they may drop something the
 * shop will in fact charge them for.
 */
final readonly class DalBundleItems
{
    /**
     * The name Commercial's `ProductExtension` registers its `bundle_item` association under.
     *
     * One constant for two uses that must never drift apart: {@see DalCriteriaBuilder} names it to
     * load the association and {@see DalBundleSupport} names it to ask whether it exists, while
     * this class reads it back out of the entity's extension bag under the same name.
     */
    public const ASSOCIATION = 'bundleItems';

    /**
     * @return list<BundleItem> in the merchant's own `position` order, as the association was sorted
     */
    public function of(Entity $product): array
    {
        $rows = $product->getExtension(self::ASSOCIATION);

        if (!is_iterable($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $item = $row instanceof Entity ? $this->item($row) : null;

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * One `bundle_item` row, or null when its member product cannot be named.
     *
     * Defaults match the database rather than convenience: `bundle_item.quantity` and
     * `bundle_item.required` are both `NOT NULL DEFAULT 1`, and an unreadable flag falls back to
     * *required* — the direction that cannot tell a shopper they may drop something the shop will
     * charge them for.
     */
    private function item(Entity $row): ?BundleItem
    {
        $member = EntityValue::of($row, 'product');
        $name = $member instanceof Entity ? EntityValue::inheritedString($member, 'name') : null;

        if ($name === null) {
            return null;
        }

        $quantity = EntityValue::of($row, 'quantity');
        $required = EntityValue::of($row, 'required');

        return new BundleItem(
            name: $name,
            quantity: \is_int($quantity) ? max(1, $quantity) : 1,
            required: \is_bool($required) ? $required : true,
        );
    }
}
