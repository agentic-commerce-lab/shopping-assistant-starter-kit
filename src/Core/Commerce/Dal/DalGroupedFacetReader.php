<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\BucketResult;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;

/**
 * Folds the grouped aggregations — `properties` and `options` — into one `properties.<Group>`
 * namespace.
 *
 * Split out of {@see DalFacetReader} (cyclomatic-complexity, kan-defect) rather than suppressed.
 *
 * **Why the two are folded together.** In Shopware a variant's distinguishing values live in the
 * `options` association while other filterable values live in `properties`. To a shopper both are
 * just "colour" and "size", the fixture catalogue models them as one namespace, and Shopware's own
 * listing filter aggregates both into a single property filter.
 * {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionFilterResolver} looks up
 * `properties.<Group>`, so keeping them apart would mean every colour and size constraint is
 * silently dropped against a real shop while working perfectly against fixtures — a divergence
 * the gateway seam is specifically meant to prevent.
 */
final readonly class DalGroupedFacetReader
{
    public function __construct(
        private DalBucketKeys $keys = new DalBucketKeys(),
    ) {}

    /**
     * @param list<BucketResult> $aggregations
     *
     * @return list<Facet>
     */
    public function facets(array $aggregations): array
    {
        /** @var array<string, list<string>> $grouped */
        $grouped = [];

        foreach ($aggregations as $aggregation) {
            $this->collect($aggregation, $grouped);
        }

        $facets = [];
        foreach ($grouped as $group => $values) {
            $facets[] = new Facet(DalFacetReader::GROUP_PREFIX . $group, FacetType::Terms, values: $values);
        }

        return $facets;
    }

    /**
     * Values for the same group name coming from both associations are unioned rather than one
     * overwriting the other, and first-seen order is preserved — the catalogue's order is
     * information, and reordering it changes which facet `TermsFacetFinder` matches first.
     *
     * A bucket with no nested aggregation is skipped rather than becoming an empty facet: an
     * empty facet reports the group as available with no values, and the resolver would then
     * emit a filter matching nothing instead of recording a drop.
     *
     * @param array<string, list<string>> $grouped
     */
    private function collect(BucketResult $result, array &$grouped): void
    {
        foreach ($result->getBuckets() as $bucket) {
            $group = $bucket->getKey();
            $nested = $bucket->getResult();

            if ($group === null || $group === '' || !$nested instanceof BucketResult) {
                continue;
            }

            $grouped[$group] = $this->union($grouped[$group] ?? [], $this->keys->of($nested->getBuckets()));
        }
    }

    /**
     * @param list<string> $existing
     * @param list<string> $incoming
     *
     * @return list<string>
     */
    private function union(array $existing, array $incoming): array
    {
        foreach ($incoming as $value) {
            if (!\in_array($value, $existing, strict: true)) {
                $existing[] = $value;
            }
        }

        return $existing;
    }
}
