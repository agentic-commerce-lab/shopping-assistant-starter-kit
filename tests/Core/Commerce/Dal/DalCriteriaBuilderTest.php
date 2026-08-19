<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Each assertion here is a guarantee this project already makes elsewhere and must not lose
 * when the query crosses into the DAL.
 */
final class DalCriteriaBuilderTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';
    private const BLUE_M_ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    /**
     * @param array<array-key, Filter> $filters
     * @param class-string $class
     */
    private function filtersOfType(array $filters, string $class): int
    {
        return \count(array_filter($filters, static fn(Filter $filter): bool => $filter instanceof $class));
    }

    public function testRetrievalUsesTheCandidateWindowAndNotTheReturnLimit(): void
    {
        // Task 2's whole point. Truncating to the return limit here would put ranking back
        // in charge of the answer, before variant resolution can disambiguate anything.
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey', limit: 1, candidateLimit: 20),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame(20, $criteria->getLimit());
    }

    public function testTheSearchTermReachesTheCriteria(): void
    {
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame('Jersey', $criteria->getTerm());
    }

    public function testABlockedProductIsExcludedAtRetrievalRatherThanAfterwards(): void
    {
        // D5: a blocked item must never enter model context, so the exclusion is a
        // retrieval filter. BlocklistFilter still runs afterwards as the second line.
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(blockedProductIds: [self::BLUE_M_ID]),
            self::CHANNEL,
        );

        self::assertSame(1, $this->filtersOfType($criteria->getFilters(), NotFilter::class));
    }

    public function testWithNothingBlockedNoExclusionFilterIsAddedAtAll(): void
    {
        // An empty NotFilter would exclude nothing but still cost a join, and it would make
        // the previous test pass for the wrong reason.
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame(0, $this->filtersOfType($criteria->getFilters(), NotFilter::class));
    }

    public function testItScopesToTheSalesChannelSoAnotherChannelsProductsCannotAppear(): void
    {
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame(1, $this->filtersOfType($criteria->getFilters(), ProductAvailableFilter::class));
    }

    public function testASoldOutVariantIsStillRetrievable(): void
    {
        // ProductAvailableFilter checks visibility and active, NOT stock. If a closeout
        // filter is ever added here, "is the blue M in stock?" becomes unanswerable — the
        // assistant would report that the product does not exist, which is a different
        // lie from the one we are avoiding but still a lie.
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame(0, $this->filtersOfType($criteria->getFilters(), ProductCloseoutFilter::class));
    }

    public function testAModelSuppliedFilterClauseBecomesADalFilter(): void
    {
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey', filters: [new FilterClause('price', FilterOperator::Range, [
                'lte' => 80.0,
            ])]),
            new CatalogScope(),
            self::CHANNEL,
        );

        // Availability filter plus the clause; the clause was neither dropped nor doubled.
        self::assertCount(2, $criteria->getFilters());
    }

    public function testTheAssociationsTheMapperReadsAreRequested(): void
    {
        // The mapper drops an option whose group is not loaded, by design. If these
        // associations are missing, every card silently loses its options and a variant
        // question can never be answered — with no error anywhere.
        $criteria = (new DalCriteriaBuilder())->build(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(),
            self::CHANNEL,
        );

        // addAssociation() nests rather than storing the dotted path as a key: 'options.group'
        // registers 'options' on this criteria and 'group' on the nested one. Verified in
        // Criteria::addAssociation() (installed 6.7.13), so the assertion walks the nesting
        // rather than asking for a key that never exists.
        foreach (['options', 'properties', 'deliveryTime', 'cover'] as $path) {
            self::assertTrue($criteria->hasAssociation($path), \sprintf('missing association "%s"', $path));
        }

        foreach (['options' => 'group', 'properties' => 'group', 'cover' => 'media'] as $parent => $child) {
            self::assertTrue(
                $criteria->getAssociation($parent)->hasAssociation($child),
                \sprintf('missing nested association "%s.%s"', $parent, $child),
            );
        }
    }
}
