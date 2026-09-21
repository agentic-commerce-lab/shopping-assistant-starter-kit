<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SalesChannel\ProductCloseoutFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Split out of {@see DalCriteriaBuilderTest} the way {@see DalCriteriaBuilderBundleTest} was, and
 * for the same reason: one concern per class, and that one is at its method budget.
 *
 * Everything here is about the criteria a read that OFFERS products gets on top of the shared one.
 * What a read must NOT get lives next door, in that class's
 * `testASoldOutVariantIsStillRetrievable()`.
 */
final class DalCriteriaBuilderDiscoveryTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    /**
     * @param array<array-key, Filter> $filters
     * @param class-string $class
     */
    private function filtersOfType(array $filters, string $class): int
    {
        return \count(array_filter($filters, static fn(Filter $filter): bool => $filter instanceof $class));
    }

    public function testADiscoveryReadWillNotVolunteerSomethingTheCheckoutRefuses(): void
    {
        // The named method rather than a flag on build(), for the reason ToolProductSummary
        // gives for withDescriptions(): the call site has to say which contract it asked for,
        // and a boolean argument makes that distinction invisible exactly where a reviewer looks.
        $criteria = (new DalCriteriaBuilder())->buildForDiscovery(
            new ProductQuery(term: 'Jersey'),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame(1, $this->filtersOfType($criteria->getFilters(), ProductCloseoutFilter::class));
    }

    public function testAQueryThatNAMESAVariantHidesNothingFromTheShopper(): void
    {
        // Staging 2026-09-21: "habt ihr das Trail Jersey in Blau, Größe M?" answered "a jersey by
        // that exact name was not found" — the Blue/M is an out-of-stock closeout variant, and the
        // filter below removed it. The discovery/lookup split was supposed to prevent exactly that
        // and did not: the model makes ONE search_products call and never reaches get_product.
        $criteria = (new DalCriteriaBuilder())->buildForDiscovery(
            new ProductQuery(term: 'Trail Jersey', filters: [
                new FilterClause('properties.Size', FilterOperator::Equals, 'M'),
            ]),
            new CatalogScope(hideOutOfStock: true),
            self::CHANNEL,
        );

        self::assertSame(0, $this->filtersOfType($criteria->getFilters(), ProductCloseoutFilter::class));
    }

    public function testADiscoveryReadStillCarriesEverythingAnOrdinaryOneDoes(): void
    {
        // It adds to the shared criteria rather than replacing it. Losing the sales-channel scope
        // here would leak another channel's products into search and nothing else would notice.
        $criteria = (new DalCriteriaBuilder())->buildForDiscovery(
            new ProductQuery(term: 'Jersey', limit: 1, candidateLimit: 20),
            new CatalogScope(),
            self::CHANNEL,
        );

        self::assertSame(1, $this->filtersOfType($criteria->getFilters(), ProductAvailableFilter::class));
        self::assertSame('Jersey', $criteria->getTerm());
        self::assertSame(20, $criteria->getLimit());
    }
}
