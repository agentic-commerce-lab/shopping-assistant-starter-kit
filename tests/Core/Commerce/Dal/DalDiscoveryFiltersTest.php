<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

/**
 * What a DISCOVERY read adds on top of the shared criteria — and nothing a lookup by id gets, which
 * is the whole reason these filters live here rather than in `DalCriteriaBuilder`.
 */
final class DalDiscoveryFiltersTest extends TestCase
{
    /**
     * @param list<Filter> $filters
     * @param class-string $class
     */
    private function countOfType(array $filters, string $class): int
    {
        return \count(array_filter($filters, static fn(Filter $filter): bool => $filter instanceof $class));
    }

    /**
     * @param list<Filter> $filters
     *
     * @return list<string>
     */
    private function fieldsOutsideCloseout(array $filters): array
    {
        $fields = [];

        foreach ($filters as $filter) {
            if ($filter instanceof ProductCloseoutFilter) {
                continue;
            }

            foreach ($filter->getFields() as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public function testAnUnbuyableCloseoutProductIsNeverVolunteered(): void
    {
        // Not a setting. A closeout product out of stock cannot be ordered at all — Shopware's
        // own `available` is false for it — so recommending one proposes something the checkout
        // will refuse. The shop's `hideCloseoutProductsWhenOutOfStock` is deliberately NOT read:
        // it decides whether a product PAGE stays reachable, which says nothing about whether
        // the assistant should offer the product.
        $filters = DalDiscoveryFilters::of(new CatalogScope());

        self::assertSame(1, $this->countOfType($filters, ProductCloseoutFilter::class));
    }

    public function testByDefaultAnOrdinaryProductOutOfStockStaysVisible(): void
    {
        // It can still be ordered; it just arrives later. Whether that is worth showing is the
        // merchant's call, so the default leaves it alone.
        $filters = DalDiscoveryFilters::of(new CatalogScope());

        self::assertSame([], $this->fieldsOutsideCloseout($filters));
    }

    public function testTheMerchantCanHideOrdinaryProductsThatAreOutOfStock(): void
    {
        $filters = DalDiscoveryFilters::of(new CatalogScope(hideOutOfStock: true));

        self::assertContains('stock', $this->fieldsOutsideCloseout($filters));
    }

    public function testTheStockConditionIsGuardedSoAFamilyParentSurvivesIt(): void
    {
        // Measured 2026-09-21 against a 118,232-row shop: `stock = 0` matches 4,524 rows, of
        // which 259 are family parents whose variants are ALL in stock — the parent row carries
        // its own stock column, not the sum of its children. Excluding those hides a product a
        // shopper can buy five sizes of, so the condition may only apply to a sellable unit.
        $fields = $this->fieldsOutsideCloseout(DalDiscoveryFilters::of(new CatalogScope(hideOutOfStock: true)));

        self::assertContains('parentId', $fields);
        self::assertContains('childCount', $fields);
    }
}
