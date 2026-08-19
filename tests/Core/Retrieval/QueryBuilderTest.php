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
        // @mago-expect analysis:possibly-null-property-access
        // assertCount above guarantees index 0 exists; the analyzer cannot correlate
        // that runtime narrowing to the static `list<FilterClause>` element type.
        self::assertSame('price', $result->query->filters[0]->field);
        // @mago-expect analysis:possibly-null-property-access
        self::assertSame(['lte' => 40.0], $result->query->filters[0]->value);
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
        // @mago-expect analysis:possibly-null-property-access
        self::assertSame('properties.Colour', $result->query->filters[0]->field);
        // @mago-expect analysis:possibly-null-property-access
        self::assertSame('Blue', $result->query->filters[0]->value);
        self::assertSame([], $result->droppedFields);
    }
}
