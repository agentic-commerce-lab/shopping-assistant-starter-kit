<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Insights\InsightsDataScope;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettings;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettingsReader;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;

/**
 * Reads the insights card out of `system_config`, following {@see SystemConfigAssistantConfig}.
 *
 * **Separate from that class rather than six more fields on it.** `AssistantConfig` is built on
 * every shopper turn and the nightly run is the only reader of these values; a turn should not pay
 * for six lookups it will never use.
 *
 * **The reader is constructed here rather than injected**, which is not a style choice: it takes a
 * scalar prefix, so it is not autowirable, and it is not registered as a service anywhere —
 * {@see SystemConfigAssistantConfig} builds its own the same way. Injecting it is what broke this
 * branch's container until 2026-09-16: `services.xml` named a service that does not exist, every
 * PHP test passed because nothing boots the container, and `cache:clear` died with
 * `ServiceNotFoundException` so the plugin's DAL entities never registered at all.
 */
final readonly class SystemConfigInsightsSettings implements InsightsSettingsReader
{
    private StoredValueReader $stored;

    public function __construct(
        SystemConfigService $systemConfig,
        private SystemConfigLlmSettings $chatLlm,
    ) {
        $this->stored = new StoredValueReader($systemConfig, SystemConfigAssistantConfig::PREFIX);
    }

    public function forSalesChannel(?string $salesChannelId = null): InsightsSettings
    {
        // Passed through as null rather than coerced to ''. Null is the shop-wide value;
        // `SystemConfigService::load('')` throws `InvalidUuidException`, and it did — after the
        // metrics had already printed, from a line that reads like a harmless default.
        $channel = $salesChannelId;

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
    private function chatSettings(?string $channel): LlmSettings
    {
        try {
            return $this->chatLlm->forSalesChannel($channel);
        } catch (LlmException) {
            return new LlmSettings('', '', 'none');
        }
    }
}
