<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Category\CategoryEntity;

/**
 * Which loaded categories are still active — asked **after** hydration, not in the criteria.
 *
 * ## Why a SQL filter is not enough
 *
 * Shopware Commercial's Advanced Product Catalogues do not filter categories in the query. They
 * subscribe to `sales_channel.category.loaded` and call `setActive(false)` on every category the
 * active organisation unit is not released for
 * ({@see \Shopware\Commercial\B2B\AdvancedProductCatalogs\Subscriber\SalesChannelCriteriaSubscriber::addCategoryFilter}).
 * That runs after the rows are already selected, so an `EqualsFilter('active', true)` in the criteria
 * cannot see it: the database row is active, and only the hydrated object is not.
 *
 * **Verified 2026-09-01** against a Commercial shop with a catalogue that released every category
 * except Jerseys: products were correctly hidden — the *product* side is filtered in the criteria and
 * needs nothing — while {@see DalCategoryTreeReader} would still have named the restricted category
 * to the shopper as orientation after a no-match, flagged only as having no products. That
 * contradicted the reader's own docblock promise that a category hidden from the storefront
 * navigation is not advertised by the assistant either.
 *
 * The absence of Commercial changes nothing here: without it, `active` is simply what the criteria
 * already selected on, and this filter drops nothing.
 */
final class DalActiveCategories
{
    private function __construct() {}

    /**
     * @param iterable<mixed> $entities
     *
     * @return list<CategoryEntity>
     */
    public static function of(iterable $entities): array
    {
        $active = [];

        foreach ($entities as $entity) {
            if ($entity instanceof CategoryEntity && self::isActive($entity)) {
                $active[] = $entity;
            }
        }

        return $active;
    }

    /**
     * True only when the category itself says so.
     *
     * `CategoryEntity::$active` is a non-nullable typed property with **no default**, so reading it on
     * an entity the DAL only partially hydrated throws an `\Error` instead of returning anything — the
     * same hazard {@see DalProductCardMapper} guards on `calculatedPrices` (rulings R48, R49). It cost
     * this class a red test on its first run, which is the only reason the guard is here rather than a
     * bare `getActive()`.
     *
     * Where the price mapper degrades to "no advanced prices" and **keeps** the product, this degrades
     * to "not visible" and **drops** the category. The asymmetry is deliberate: an unreadable price
     * still leaves a real article the shopper asked for, while an unreadable visibility flag is exactly
     * the case where guessing yes could name a category a B2B shopper is not released for.
     */
    private static function isActive(CategoryEntity $category): bool
    {
        try {
            return $category->getActive();
        } catch (\Error) {
            return false;
        }
    }
}
