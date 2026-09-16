<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

/**
 * Where {@see InsightsGenerator} gets its settings.
 *
 * Narrow on purpose, following {@see \Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionPruner}:
 * the generator's own job is the ORDER of four steps and what survives each one failing, and that
 * is only testable if none of the four needs a container to stand up.
 */
interface InsightsSettingsReader
{
    public function forSalesChannel(?string $salesChannelId = null): InsightsSettings;
}
