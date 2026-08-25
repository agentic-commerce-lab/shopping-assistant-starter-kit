<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * Everything one benchmark run measured, as one immutable value.
 *
 * A value object rather than an array so the renderer and the tests agree on the field names by
 * construction — an `array<string, mixed>` here would make every field access `mixed` downstream and
 * fail this project's analyzer, and a typo in a key would be a runtime surprise in a command whose
 * whole output is those keys.
 */
final class BenchmarkReport
{
    /** @param list<QueryMeasurement> $queries */
    public function __construct(
        public readonly string $shopLabel,
        public readonly VocabularyMeasurement $vocabulary,
        public readonly FacetProbeMeasurement $facetProbe,
        public readonly array $queries,
        public readonly CardEndpointMeasurement $cards,
    ) {}
}
