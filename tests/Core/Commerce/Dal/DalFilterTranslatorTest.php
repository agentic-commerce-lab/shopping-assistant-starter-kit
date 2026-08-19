<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalFilterTranslator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;

/**
 * The clause field names are LOGICAL names from the catalogue facet set, not DAL paths. This
 * class is the only place the two vocabularies meet, so these are the assertions that stop a
 * facet-derived filter from becoming a query on a field that does not exist.
 */
final class DalFilterTranslatorTest extends TestCase
{
    public function testAPriceCeilingBecomesARangeFilter(): void
    {
        $filter = (new DalFilterTranslator())->translate(new FilterClause('price', FilterOperator::Range, [
            'lte' => 80.0,
        ]));

        self::assertInstanceOf(RangeFilter::class, $filter);
        self::assertSame('price', $filter->getField());
    }

    public function testABrandBecomesAManufacturerNameFilterAndNotAPropertyLookup(): void
    {
        // BrandFilterResolver emits `properties.Manufacturer` because that is what the fixture
        // catalogue calls it. In Shopware the manufacturer is its own association, so a
        // property lookup here would find nothing while reporting the filter as applied.
        $filter = (new DalFilterTranslator())->translate(new FilterClause(
            'properties.Manufacturer',
            FilterOperator::Equals,
            'Shimano',
        ));

        self::assertInstanceOf(EqualsFilter::class, $filter);
        self::assertSame('manufacturer.name', $filter->getField());
        self::assertSame('Shimano', $filter->getValue());
    }

    public function testAVariantSelectionMatchesEitherPropertiesOrOptions(): void
    {
        // Shopware splits what a shopper experiences as one thing: a variant's distinguishing
        // values live in `options`, other filterable values in `properties`. Checking only one
        // drops every colour and size constraint against a real shop.
        $filter = (new DalFilterTranslator())->translate(new FilterClause(
            'properties.Colour',
            FilterOperator::Equals,
            'Blue',
        ));

        self::assertInstanceOf(OrFilter::class, $filter);

        $fields = [];
        foreach ($filter->getQueries() as $pair) {
            self::assertInstanceOf(MultiFilter::class, $pair);
            foreach ($pair->getQueries() as $leaf) {
                self::assertInstanceOf(EqualsFilter::class, $leaf);
                $fields[] = $leaf->getField();
            }
        }

        self::assertSame(
            [
                'properties.group.name',
                'properties.name',
                'options.group.name',
                'options.name',
            ],
            $fields,
        );
    }

    public function testTheGroupAndTheValueAreAndedSoTheyMustComeFromTheSameRow(): void
    {
        // ORing them would match a product that merely has a Colour group and an "M" somewhere
        // else entirely — a variant answer assembled from two unrelated rows.
        $filter = (new DalFilterTranslator())->translate(new FilterClause(
            'properties.Size',
            FilterOperator::Equals,
            'M',
        ));

        self::assertInstanceOf(OrFilter::class, $filter);

        foreach ($filter->getQueries() as $pair) {
            self::assertInstanceOf(MultiFilter::class, $pair);
            self::assertSame(MultiFilter::CONNECTION_AND, $pair->getOperator());
        }
    }

    public function testAnUnknownFieldThrowsRatherThanReachingTheDatabase(): void
    {
        // A pass-through produces an invalid-field DAL error far from its cause; a silent drop
        // widens the search behind a trace claiming the filter was applied, which is the
        // failure ruling R20 records and which only a live run caught.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('categoryPath');

        (new DalFilterTranslator())->translate(new FilterClause('categoryPath', FilterOperator::Equals, 'Jerseys'));
    }

    public function testAnEmptyRangeThrowsRatherThanMatchingEverything(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DalFilterTranslator())->translate(new FilterClause('price', FilterOperator::Range, []));
    }
}
