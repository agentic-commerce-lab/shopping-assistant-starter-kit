<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder;
use Swag\AssistantStarterKit\Core\Commerce\Dal\StreamFilters;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Blocked Dynamic Product Groups, where they meet the criteria. What a GROUP resolves to is
 * {@see DalStreamFiltersTest}'s subject; this is only about it reaching the query, and reaching it
 * as an exclusion rather than beside one.
 */
final class DalCriteriaBuilderStreamTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const BLUE_M_ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    /**
     * @param array<array-key, Filter> $filters
     *
     * @return list<NotFilter>
     */
    private function notFilters(array $filters): array
    {
        return array_values(array_filter($filters, static fn(Filter $f): bool => $f instanceof NotFilter));
    }

    private function resolving(Filter ...$perStream): StreamFilters
    {
        return new class(array_values($perStream)) implements StreamFilters {
            /** @param list<Filter> $filters */
            public function __construct(
                private readonly array $filters,
            ) {}

            public function filtersFor(array $streamIds): array
            {
                return $streamIds === [] ? [] : $this->filters;
            }
        };
    }

    public function testABlockedGroupIsExcludedAtRetrievalRatherThanAfterwards(): void
    {
        // Same guarantee as a blocked product (D5): never fetched, so it cannot reach the model.
        // Unlike a blocked product it gets no second pass — BlocklistFilter cannot re-check a group
        // without a query per card — which is why this one has to be right here.
        $criteria = (new DalCriteriaBuilder(streams: $this->resolving(new EqualsFilter('manufacturerId', 'x'))))->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(blockedStreamIds: ['s1']),
            self::CHANNEL,
        );

        self::assertCount(1, $this->notFilters($criteria->getFilters()));
    }

    public function testAGroupAndAProductBlockedTogetherStayOneExclusion(): void
    {
        // The blocked sets are OR-ed inside a single NOT. A second NotFilter beside the first would
        // AND them, and a product would then have to be in BOTH to be excluded.
        $criteria = (new DalCriteriaBuilder(streams: $this->resolving(new EqualsFilter('manufacturerId', 'x'))))->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(blockedProductIds: [self::BLUE_M_ID], blockedStreamIds: ['s1']),
            self::CHANNEL,
        );

        $exclusions = $this->notFilters($criteria->getFilters());
        self::assertCount(1, $exclusions);

        $fields = ($exclusions[0] ?? null)?->getFields() ?? [];
        self::assertContains('manufacturerId', $fields);
        self::assertContains('id', $fields);
    }

    public function testAShopWithNoGroupResolverIsNotQuietlyEmptied(): void
    {
        // The default collaborator answers nothing, exactly as BundlesUnavailable does for bundles.
        // A builder constructed without one — every other test here, and the eval harness — must
        // behave as if no group were configured, not as if every group blocked everything.
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(blockedStreamIds: ['s1']),
            self::CHANNEL,
        );

        self::assertSame([], $this->notFilters($criteria->getFilters()));
    }
}
