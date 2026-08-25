<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * The cards endpoint's cost for one full request.
 *
 * `lookups` is the count the spec asked for: `AssistantCardController` performs one catalogue lookup
 * per id, and `CardIdList::MAX_IDS` is 12 because of it. Measured at the maximum, since that is the
 * request the storefront actually sends when a turn renders a full row.
 */
final class CardEndpointMeasurement
{
    public function __construct(
        public readonly int $ids,
        public readonly int $lookups,
        public readonly float $totalMs,
    ) {}
}
