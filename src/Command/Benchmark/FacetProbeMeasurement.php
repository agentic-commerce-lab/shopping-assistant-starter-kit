<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * The facet probe, measured cold and warm.
 *
 * Three tiers, because `FacetProbe` has three: `live` straight from the catalogue, `cache` from its
 * own instance, and `shared` from the cross-request pool. They differ by orders of magnitude on a
 * real catalogue, so one number would describe either the first turn of a conversation or every
 * later one and never say which.
 *
 * `sharedHit` is what makes the shared column readable: on a cold pool that probe populates it and
 * reports a live-shaped duration, which would otherwise look like the cache not working. Run the
 * command twice — the second run is the one whose shared number means anything.
 */
final class FacetProbeMeasurement
{
    public function __construct(
        public readonly float $liveMs,
        public readonly float $cachedMs,
        public readonly float $sharedMs,
        public readonly bool $sharedHit,
    ) {}
}
