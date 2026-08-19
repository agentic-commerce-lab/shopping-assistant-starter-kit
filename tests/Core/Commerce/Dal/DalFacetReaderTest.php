<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalFacetReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

final class DalFacetReaderTest extends TestCase
{
    public function testATermsAggregationBecomesAFacetCarryingTheCatalogsOwnSpelling(): void
    {
        // Ruling R20: the field AND the value must originate in the catalogue. A facet that
        // normalises its values makes the model's "BLUE" match nothing — and it fails
        // silently, behind a clean, fully-applied trace.
        $facets = (new DalFacetReader())->read(new AggregationResultCollection([
            new TermsResult('properties.Colour', [
                new Bucket('Blue', 3, null),
                new Bucket('Black', 3, null),
            ]),
        ]));

        $facet = $facets->get('properties.Colour');
        self::assertNotNull($facet);
        self::assertSame(FacetType::Terms, $facet->type);
        self::assertSame(['Blue', 'Black'], $facet->values);
    }

    public function testAStatsAggregationBecomesARangeFacetAndNeverATermsOne(): void
    {
        // Ruling R54: Range facets are excluded from the catalogue-vocabulary block precisely
        // because their min/max are numbers, and putting numbers in front of the model is what
        // this project forbids. Mistyping one as Terms would leak them into the prompt.
        $facets = (new DalFacetReader())->read(new AggregationResultCollection([
            new StatsResult('price', 12.9, 79.9, 46.4, 278.4),
        ]));

        $facet = $facets->get('price');
        self::assertNotNull($facet);
        self::assertSame(FacetType::Range, $facet->type);
        self::assertSame(12.9, $facet->min);
        self::assertSame(79.9, $facet->max);
        self::assertSame([], $facet->values);
    }

    public function testABucketWithNoKeyIsDroppedRatherThanBecomingAnEmptyValue(): void
    {
        // An empty-string facet value would be offered to the model as a real option.
        $facets = (new DalFacetReader())->read(new AggregationResultCollection([
            new TermsResult('properties.Size', [
                new Bucket(null, 1, null),
                new Bucket('M', 2, null),
            ]),
        ]));

        $facet = $facets->get('properties.Size');
        self::assertNotNull($facet);
        self::assertSame(['M'], $facet->values);
    }

    public function testAnEmptyAggregationSetYieldsNoFacetsRatherThanAnError(): void
    {
        // A catalogue with no properties configured is a legitimate shop, and QueryBuilder
        // must then drop every constraint rather than the turn.
        self::assertSame(
            [],
            (new DalFacetReader())
                ->read(new AggregationResultCollection())
                ->fields(),
        );
    }

    public function testAGroupedAggregationBecomesOnePropertiesFacetPerGroup(): void
    {
        // This is the exact shape VariantSelectionFilterResolver looks up
        // (`properties.<Group>`), so it is the shape the fixture gateway's naming must be
        // matched with. Getting it wrong drops every colour and size constraint silently.
        $facets = (new DalFacetReader())->read(new AggregationResultCollection([
            new TermsResult('properties', [
                new Bucket('Colour', 6, new TermsResult('values', [
                    new Bucket('Blue', 3, null),
                    new Bucket('Black', 3, null),
                ])),
                new Bucket('Size', 6, new TermsResult('values', [
                    new Bucket('S', 2, null),
                    new Bucket('M', 2, null),
                ])),
            ]),
        ]));

        $colour = $facets->get('properties.Colour');
        self::assertNotNull($colour);
        self::assertSame(['Blue', 'Black'], $colour->values);

        $size = $facets->get('properties.Size');
        self::assertNotNull($size);
        self::assertSame(['S', 'M'], $size->values);
    }

    public function testVariantOptionsAndPropertiesForOneGroupAreUnionedNotOverwritten(): void
    {
        // In Shopware a variant's distinguishing values live in `options` and non-variant
        // filterable values in `properties` — two associations, one shopper-facing concept.
        // If the second aggregation overwrote the first, half the catalogue's own vocabulary
        // would be missing from the facet the model is offered.
        $facets = (new DalFacetReader())->read(new AggregationResultCollection([
            new TermsResult('properties', [
                new Bucket('Colour', 3, new TermsResult('values', [new Bucket('Blue', 3, null)])),
            ]),
            new TermsResult('options', [
                new Bucket('Colour', 3, new TermsResult('values', [
                    new Bucket('Blue', 3, null),
                    new Bucket('Black', 3, null),
                ])),
            ]),
        ]));

        $colour = $facets->get('properties.Colour');
        self::assertNotNull($colour);
        self::assertSame(['Blue', 'Black'], $colour->values);
    }

    public function testAGroupBucketWithNoNestedAggregationIsSkippedRatherThanBecomingAnEmptyFacet(): void
    {
        // An empty facet would report the group as available with no values, and
        // VariantSelectionFilterResolver would then emit a filter matching nothing instead of
        // recording a drop.
        $facets = (new DalFacetReader())->read(new AggregationResultCollection([
            new TermsResult('properties', [new Bucket('Colour', 3, null)]),
        ]));

        self::assertNull($facets->get('properties.Colour'));
    }
}
