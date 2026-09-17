<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Insights\Judge\ValidatedFindings;

/**
 * The half of the night that costs money.
 *
 * Behind an interface so that the generator's tests can assert "spends nothing" by failing if this
 * is reached at all — the only way to make that claim without a billing API.
 *
 * Returns {@see ValidatedFindings} rather than a bare list because a guard that silently removes
 * things has to say how many: "no findings" and "every finding failed the quote check" mean opposite
 * things, and a replay that reported the first while possibly meaning the second is what added this.
 */
interface InsightJudge
{
    /**
     * @param list<ConversationTrace> $traces
     *
     * @throws \JsonException                                       when the model's answer is not a list of findings
     * @throws \Swag\AssistantStarterKit\Core\Llm\LlmException      when the provider cannot be reached
     *
     */
    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): ValidatedFindings;
}
