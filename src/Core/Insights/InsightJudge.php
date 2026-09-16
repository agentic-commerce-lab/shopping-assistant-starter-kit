<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Insights\Judge\JudgeFinding;

/**
 * The half of the night that costs money.
 *
 * Behind an interface so that the generator's tests can assert "spends nothing" by failing if this
 * is reached at all — the only way to make that claim without a billing API.
 */
interface InsightJudge
{
    /**
     * @param list<ConversationTrace> $traces
     *
     * @throws \JsonException                                       when the model's answer is not a list of findings
     * @throws \Swag\AssistantStarterKit\Core\Llm\LlmException      when the provider cannot be reached
     *
     * @return list<JudgeFinding>
     */
    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): array;
}
