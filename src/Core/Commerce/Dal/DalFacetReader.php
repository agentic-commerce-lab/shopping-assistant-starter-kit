<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\BucketResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Turns DAL aggregation results into a {@see FacetSet} — the catalogue's own answer to
 * "which fields and values does this shop actually offer?".
 *
 * That answer is what stops the model from inventing a field name or a value: `QueryBuilder` only
 * ever emits a filter whose field is in this set, and (ruling R20) with the value spelled exactly
 * as the catalogue spells it.
 *
 * Three shapes are recognised, and the type split is load-bearing:
 *
 * - **A stats aggregation** becomes a `Range` facet. Ruling R54 excludes `Range` facets from the
 *   catalogue-vocabulary prompt block precisely because their bounds are numbers, and putting
 *   numbers in front of the model is what this project forbids. A stats aggregation mistyped as
 *   `Terms` would leak prices into the prompt.
 * - **A terms aggregation named in {@see self::GROUPED_AGGREGATIONS}** carries group names in its
 *   buckets and values in a nested aggregation. {@see DalGroupedFacetReader} folds those into one
 *   `properties.<Group>` namespace.
 * - **Any other terms aggregation** becomes a `Terms` facet under its own name, which is how
 *   `properties.Manufacturer` reaches the facet set.
 */
final readonly class DalFacetReader
{
    /**
     * Aggregation names whose buckets are group names rather than values. Keep in sync with the
     * aggregations {@see DalCommerceGateway::facets()} registers.
     */
    public const GROUPED_AGGREGATIONS = ['properties', 'options'];

    public const GROUP_PREFIX = 'properties.';

    public function __construct(
        private DalGroupedFacetReader $groupedReader = new DalGroupedFacetReader(),
        private DalBucketKeys $keys = new DalBucketKeys(),
    ) {}

    public function read(AggregationResultCollection $aggregations): FacetSet
    {
        $facets = [];
        $grouped = [];

        foreach ($aggregations as $aggregation) {
            if ($aggregation instanceof StatsResult) {
                $facets[] = new Facet(
                    field: $aggregation->getName(),
                    type: FacetType::Range,
                    min: $this->float($aggregation->getMin()),
                    max: $this->float($aggregation->getMax()),
                );

                continue;
            }

            if (!$aggregation instanceof BucketResult) {
                continue;
            }

            if (\in_array($aggregation->getName(), self::GROUPED_AGGREGATIONS, strict: true)) {
                $grouped[] = $aggregation;

                continue;
            }

            $facets[] = new Facet(
                field: $aggregation->getName(),
                type: FacetType::Terms,
                values: $this->keys->of($aggregation->getBuckets()),
            );
        }

        return new FacetSet([...$facets, ...$this->groupedReader->facets($grouped)]);
    }

    /**
     * `StatsResult` declares its bounds as `mixed` because the aggregated field need not be
     * numeric (a date range aggregates to strings). A non-numeric bound cannot express a price
     * range, so it becomes null — the facet is then present but unbounded, `QueryBuilder` drops
     * the constraint and records the drop, and the failure is observable rather than silent.
     */
    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
