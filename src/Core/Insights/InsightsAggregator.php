<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Insights\Metric\CartFunnel;
use Swag\AssistantStarterKit\Core\Insights\Metric\DescriptionCoverage;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;
use Swag\AssistantStarterKit\Core\Insights\Metric\TurnHealth;

/**
 * Trace events in, counts out. No repository, no clock, no model.
 *
 * Purity is the point, twice over. It is what lets the four metrics be asserted from fixtures
 * rather than from a seeded shop, and it is why a merchant's nightly numbers can be recomputed from
 * an export months later — which is exactly how this feature's own design was validated on
 * 2026-09-16, by replaying a detector over 131 archived conversations. A metric that needed the
 * database to compute could not have been checked that way.
 *
 * One class per metric behind it (see `Metric/`), so the gate's per-class complexity budget holds
 * and so a merchant-facing number can be read on its own.
 */
final class InsightsAggregator
{
    private function __construct() {}

    /** @param list<ConversationTrace> $traces */
    public static function aggregate(array $traces): InsightMetrics
    {
        return new InsightMetrics(
            SearchOutcomes::of($traces),
            DescriptionCoverage::of($traces),
            TurnHealth::of($traces),
            CartFunnel::of($traces),
        );
    }
}
