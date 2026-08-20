<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;

/**
 * Builds {@see LlmSettings} from the merchant's `config.xml`, with an environment override.
 *
 * **The environment wins over stored config, deliberately.** Shopware's system config has no real
 * secret storage: a key entered in the admin form is readable by anyone with config access and
 * travels in every database backup. `config.xml` says so in its own help text, and the spec calls
 * the env var the preferred path. Letting the environment take precedence is what makes that advice
 * actionable rather than decorative — and it means the probe command and the widget read the same
 * credentials from one place during development.
 *
 * An incomplete configuration **throws**. The alternative is an assistant that appears installed and
 * fails at the first shopper message, with the failure surfacing as a turn error rather than as a
 * configuration problem.
 */
final readonly class SystemConfigLlmSettings
{
    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    public function forSalesChannel(string $salesChannelId): LlmSettings
    {
        $baseUrl = $this->setting('llmBaseUrl', 'ASSISTANT_LLM_BASE_URL', $salesChannelId);
        $model = $this->setting('llmModel', 'ASSISTANT_LLM_MODEL', $salesChannelId);
        $apiKey = $this->setting('llmApiKey', 'ASSISTANT_LLM_API_KEY', $salesChannelId);

        $missing = [];
        foreach (['llmBaseUrl' => $baseUrl, 'llmModel' => $model, 'llmApiKey' => $apiKey] as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        // The `$model !== ''` half is redundant given the loop, and deliberately spelled out:
        // LlmSettings::$model is `non-empty-string`, and an inline check is what makes that
        // provable rather than merely true. A pragma would assert the same thing without checking.
        if ($missing !== [] || $model === '') {
            // All three are checked, not just the base URL. Ruling R43: the eval suite once checked
            // only the URL, so a half-configured setup attempted a real network call.
            throw new LlmException(\sprintf('The assistant is not configured. Missing: %s. Set them in the plugin settings, or '
            . 'as ASSISTANT_LLM_BASE_URL / ASSISTANT_LLM_MODEL / ASSISTANT_LLM_API_KEY.', implode(', ', $missing)));
        }

        return new LlmSettings(baseUrl: $baseUrl, apiKey: $apiKey, model: $model);
    }

    /**
     * Whether the assistant is usable at all, without throwing.
     *
     * The controller needs this to answer a shopper politely instead of turning a missing API key
     * into a 500, and the widget needs it to stay hidden on a shop that never configured a model.
     */
    public function isConfigured(string $salesChannelId): bool
    {
        return (
            $this->setting('llmBaseUrl', 'ASSISTANT_LLM_BASE_URL', $salesChannelId) !== ''
            && $this->setting('llmModel', 'ASSISTANT_LLM_MODEL', $salesChannelId) !== ''
            && $this->setting('llmApiKey', 'ASSISTANT_LLM_API_KEY', $salesChannelId) !== ''
        );
    }

    private function setting(string $key, string $envName, string $salesChannelId): string
    {
        $env = getenv($envName);

        if (\is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return trim($this->systemConfig->getString(SystemConfigAssistantConfig::PREFIX . $key, $salesChannelId));
    }
}
