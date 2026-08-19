<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;

/**
 * Bucket keys, in the catalogue's own spelling and the aggregation's own order.
 *
 * Split out of {@see DalFacetReader} (cyclomatic-complexity, kan-defect) rather than suppressed,
 * and shared by the flat and grouped facet paths so both obey the same two rules:
 *
 * - **No normalisation.** Ruling R20: the field and the value must both originate in the
 *   catalogue. Lowercasing or sorting here makes the model's "BLUE" match nothing, silently,
 *   behind a trace that reports the filter as fully applied.
 * - **A null or empty key is dropped.** It means the aggregation bucketed rows with no value for
 *   that field. Keeping it would offer the model an empty-string facet value as a real option.
 */
final readonly class DalBucketKeys
{
    /**
     * @param list<Bucket> $buckets
     *
     * @return list<string>
     */
    public function of(array $buckets): array
    {
        $keys = [];

        foreach ($buckets as $bucket) {
            $key = $bucket->getKey();

            if ($key === null || $key === '') {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }
}
