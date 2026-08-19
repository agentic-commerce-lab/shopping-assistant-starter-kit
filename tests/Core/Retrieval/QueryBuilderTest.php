<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent;

final class QueryBuilderTest extends TestCase
{
    private function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('price', FacetType::Range, min: 0.0, max: 199.0),
            new Facet('properties.Colour', FacetType::Terms, values: ['Blue', 'Black']),
        ]);
    }

    public function testAppliesAPriceConstraintAsARangeFilter(): void
    {
        $result = (new QueryBuilder())->build(new ShopperIntent(term: 'brake pads', priceMax: 40.0), $this->facets());

        self::assertSame('brake pads', $result->query->term);
        self::assertCount(1, $result->query->filters);
        $filter = $result->query->filters[0] ?? null;
        self::assertNotNull($filter);
        self::assertSame('price', $filter->field);
        self::assertSame(['lte' => 40.0], $filter->value);
        self::assertSame([], $result->droppedFields);
    }

    public function testDropsAndRecordsAConstraintWithNoMatchingFacet(): void
    {
        $result = (new QueryBuilder())->build(new ShopperIntent(term: 'jersey', selections: [new VariantSelection(
            'waterproof',
            'Fabric',
        )]), $this->facets());

        self::assertSame([], $result->query->filters);
        self::assertSame(['properties.Fabric'], $result->droppedFields);
    }

    public function testMatchesAGrouplessSelectionAgainstAnyFacetHoldingThatValue(): void
    {
        $result = (new QueryBuilder())->build(new ShopperIntent(term: 'jersey', selections: [new VariantSelection(
            'Blue',
        )]), $this->facets());

        self::assertCount(1, $result->query->filters);
        $filter = $result->query->filters[0] ?? null;
        self::assertNotNull($filter);
        self::assertSame('properties.Colour', $filter->field);
        self::assertSame('Blue', $filter->value);
        self::assertSame([], $result->droppedFields);
    }

    public function testUsesTheCatalogsCanonicalSpellingWhenTheModelsCasingDiffers(): void
    {
        $result = (new QueryBuilder())->build(new ShopperIntent(term: 'jersey', selections: [new VariantSelection(
            'BLUE',
        )]), $this->facets());

        self::assertCount(1, $result->query->filters);
        $filter = $result->query->filters[0] ?? null;
        self::assertNotNull($filter);
        self::assertSame('properties.Colour', $filter->field);
        self::assertSame('Blue', $filter->value);
        self::assertSame([], $result->droppedFields);
    }
}
