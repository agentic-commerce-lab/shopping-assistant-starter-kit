<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Swag\AssistantStarterKit\Core\Insights\InsightsDataScope;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettings;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;

/**
 * Reads the insights card out of `system_config`, following {@see SystemConfigAssistantConfig}.
 *
 * **Separate from that class rather than six more fields on it.** `AssistantConfig` is built on
 * every shopper turn and the nightly run is the only reader of these values; a turn should not pay
 * for six lookups it will never use.
 */
final readonly class SystemConfigInsightsSettings
{
    public function __construct(
        private StoredValueReader $stored,
        private SystemConfigLlmSettings $chatLlm,
    ) {}

    public function forSalesChannel(?string $salesChannelId = null): InsightsSettings
    {
        $channel = $salesChannelId ?? '';

        return new InsightsSettings(
            enabled: $this->stored->bool('insightsEnabled', false, $channel),
            samplePercent: $this->stored->int('insightsSamplePercent', 100, $channel),
            dataScope: InsightsDataScope::fromStored($this->stored->string('insightsDataScope', $channel)),
            llm: InsightsSettings::resolveLlm(
                $this->chatSettings($channel),
                $this->stored->string('insightsLlmBaseUrl', $channel),
                $this->stored->string('insightsLlmApiKey', $channel),
                $this->stored->string('insightsLlmModel', $channel),
            ),
        );
    }

    /**
     * The chat model's settings, or an unconfigured placeholder.
     *
     * **The catch is load-bearing, not defensive.** {@see SystemConfigLlmSettings::forSalesChannel()}
     * throws when the assistant is unconfigured — deliberately, and it checks all three of URL,
     * model and key because the eval suite once checked only the URL and attempted a real network
     * call on a half-configured shop (ruling R43). But this class is read on shops that have no
     * model at all and insights switched off, and letting that propagate would turn "the assistant
     * is not set up yet" into an error raised by a feature nobody enabled.
     *
     * With the placeholder, the aggregation still runs — it needs no model — and only the judge
     * fails, with its reason recorded on the run. That is exactly the behaviour the spec's third
     * acceptance criterion asks for.
     */
    private function chatSettings(string $channel): LlmSettings
    {
        try {
            return $this->chatLlm->forSalesChannel($channel);
        } catch (LlmException) {
            return new LlmSettings('', '', 'none');
        }
    }
}
