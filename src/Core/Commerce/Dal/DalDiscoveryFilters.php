<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

/**
 * The availability filters a **discovery** read adds, and that a lookup by id must never get.
 *
 * ## Why this is not in DalCriteriaBuilder
 *
 * That builder is on the path of every product read the assistant makes — search, direct lookup,
 * variant resolution, the cart's own pre-check — and its own test
 * ({@see \Swag\AssistantStarterKit\Tests\Core\Commerce\Dal\DalCriteriaBuilderTest::testASoldOutVariantIsStillRetrievable})
 * asserts that no closeout filter is ever added there. That assertion is right and stays: asked
 * *"is the blue M still available?"*, an assistant that cannot retrieve the sold-out variant answers
 * *"no such product"*. Hiding is a property of what the assistant **offers**, not of what it can
 * **see**, so it belongs to the one caller that offers things.
 *
 * ## Two filters, and only one of them is a setting
 *
 * **{@see ProductCloseoutFilter} is unconditional.** A closeout product out of stock cannot be
 * ordered at all — Shopware computes `product.available` as
 * `(is_closeout × stock) >= (is_closeout × min_purchase)`, false for exactly this case — so
 * recommending one proposes something the checkout will refuse. That is not a merchant preference,
 * it is the same class of rule as never quoting a price the shop did not calculate.
 *
 * The shop's own `core.listing.hideCloseoutProductsWhenOutOfStock` is deliberately **not** read.
 * It decides whether a product PAGE stays reachable — for old links and search engines — which
 * says nothing about whether an assistant should volunteer the product.
 *
 * **The stock condition is the setting**, off by default, because an ordinary product out of stock
 * can still be ordered; it merely arrives later. A shop with two-day replenishment wants it shown
 * and a shop with six-week lead times does not, and only the merchant knows which they are.
 *
 * ## The guard, and the measurement that made it necessary
 *
 * A family parent's `stock` is its **own column**, never the sum of its children. Measured
 * 2026-09-21 against a 118,232-row shop: `stock = 0` matches 4,524 rows — 2,704 ordinary products
 * and 1,561 variants, both of which should go, and **259 family parents whose every variant was in
 * stock**, 9% of all families. Excluding those leaves the family reachable only as loose variant
 * rows, so a dress with five available sizes stops being one product with five sizes.
 *
 * The condition therefore applies only to a **sellable unit**: a variant, or a product with no
 * variants. Whether a family can be bought is a question about its children, and a criteria filter
 * is not holding them.
 *
 * **`childCount = 0` alone is not that test.** `child_count` is NULL on every variant row — it is an
 * inherited field, written only on the parent — so the obvious spelling silently spares all 1,561
 * sold-out variants, which are exactly what the setting exists to hide. The two facts together are
 * the same pair {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::forProductRow()}
 * already reads to classify a row.
 */
final class DalDiscoveryFilters
{
    private function __construct() {}

    /**
     * @return list<Filter>
     */
    public static function of(CatalogScope $scope): array
    {
        $filters = [new ProductCloseoutFilter()];

        if ($scope->hideOutOfStock) {
            $filters[] = new NotFilter(NotFilter::CONNECTION_AND, [
                new MultiFilter(MultiFilter::CONNECTION_AND, [
                    new EqualsFilter('stock', 0),
                    self::sellableUnit(),
                ]),
            ]);
        }

        return $filters;
    }

    /**
     * A row a shopper can put in a basket: a variant, or a product that has no variants.
     */
    private static function sellableUnit(): MultiFilter
    {
        return new MultiFilter(MultiFilter::CONNECTION_OR, [
            new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('parentId', null)]),
            new EqualsFilter('childCount', 0),
        ]);
    }
}
