<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * One shopper-shaped query, measured.
 *
 * `retrieveHits` is how many candidates retrieval actually found; `narrowedTo` is how many the model
 * would be shown. The two are different numbers at scale, which is the point — phase A found that
 * `SearchProductsTool` reports `total => count($returned)`, so the size of the shortlist is the only
 * count that reaches the model. Keeping both here is what makes that gap visible in a table.
 */
final class QueryMeasurement
{
    public function __construct(
        public readonly string $term,
        public readonly Timings $searchMs,
        public readonly int $retrieveHits,
        public readonly int $narrowedTo,
    ) {}
}
