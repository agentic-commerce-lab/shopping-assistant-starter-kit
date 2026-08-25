<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * The facet probe, measured cold and warm.
 *
 * Both, because `FacetProbe` keeps a per-instance cache and records `source: live` or `source: cache`
 * on its `facet.probe` trace event. The two differ by orders of magnitude on a real catalogue, so
 * reporting one number would describe either the first turn of a conversation or every later one, and
 * never say which.
 */
final class FacetProbeMeasurement
{
    public function __construct(
        public readonly float $liveMs,
        public readonly float $cachedMs,
    ) {}
}
