<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCategoryProducts;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder;
use Swag\AssistantStarterKit\Core\Commerce\Dal\StreamFilters;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

/**
 * *"Which of these departments hold anything?"* — asked with the merchant's exclusions, not without.
 *
 * Found on staging 2026-09-21 with every helmet blocked by a product group:
 *
 * > "Die Suche nach Fahrradhelmen hat leider keine Treffer ergeben. Wir führen unter anderem die
 * > Abteilung Helmets. Möchten Sie, dass wir dort nach bestimmten Modellen suchen?"
 *
 * The merchant blocked helmets and the assistant invited the shopper into the helmet department,
 * which holds nothing it may show. Saying yes searches it and finds nothing again.
 *
 * Nothing leaked — no blocked product was ever named — and the bug is older than product groups: the
 * count ignored blocked products and blocked categories too. Groups are what made it easy to reach,
 * because they are the first control that blocks a whole department in one click.
 */
final class DalCategoryProductsScopeTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const HELMETS = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
    private const BLOCKED_PRODUCT = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    /**
     * @param array<array-key, Filter> $filters
     *
     * @return list<NotFilter>
     */
    private function exclusions(array $filters): array
    {
        return array_values(array_filter($filters, static fn(Filter $f): bool => $f instanceof NotFilter));
    }

    private function resolvingOneGroup(): DalCriteriaBuilder
    {
        return new DalCriteriaBuilder(streams: new class implements StreamFilters {
            public function filtersFor(array $streamIds): array
            {
                return $streamIds === [] ? [] : [new EqualsFilter('manufacturerId', 'blocked-maker')];
            }
        });
    }

    public function testADepartmentCountDoesNotSeeBlockedProducts(): void
    {
        $criteria = DalCategoryProducts::criteriaFor(
            [self::HELMETS],
            new CatalogScope(blockedProductIds: [self::BLOCKED_PRODUCT]),
            new DalCriteriaBuilder(),
            self::CHANNEL,
        );

        self::assertCount(1, $this->exclusions($criteria->getFilters()));
    }

    public function testADepartmentEmptiedByAProductGroupCountsAsEmpty(): void
    {
        // The staging case. Without this the assistant offers the department it was told to hide.
        $criteria = DalCategoryProducts::criteriaFor(
            [self::HELMETS],
            new CatalogScope(blockedStreamIds: ['helmets-group']),
            $this->resolvingOneGroup(),
            self::CHANNEL,
        );

        $exclusions = $this->exclusions($criteria->getFilters());
        self::assertCount(1, $exclusions);
        self::assertContains('manufacturerId', ($exclusions[0] ?? null)?->getFields() ?? []);
    }

    public function testTheCountIsStillAskedAboutTheDepartmentsItWasGiven(): void
    {
        // The scope narrows what counts as a product; it must not replace the question. Losing this
        // filter would count the whole catalogue against every department.
        $criteria = DalCategoryProducts::criteriaFor(
            [self::HELMETS],
            new CatalogScope(blockedProductIds: [self::BLOCKED_PRODUCT]),
            new DalCriteriaBuilder(),
            self::CHANNEL,
        );

        $fields = [];

        foreach ($criteria->getFilters() as $filter) {
            foreach ($filter->getFields() as $field) {
                $fields[] = $field;
            }
        }

        self::assertContains('categoriesRo.id', $fields);
    }

    public function testAnUnrestrictedShopGetsNoExclusionAtAll(): void
    {
        // An empty NotFilter excludes nothing and still costs a join — the same reasoning
        // DalCriteriaBuilder keeps for its own, and what stops the tests above passing for the
        // wrong reason.
        $criteria = DalCategoryProducts::criteriaFor(
            [self::HELMETS],
            new CatalogScope(),
            new DalCriteriaBuilder(),
            self::CHANNEL,
        );

        self::assertSame([], $this->exclusions($criteria->getFilters()));
    }
}
