<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Llm\LlmException;

final class SystemConfigLlmSettingsTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    /** @var list<string> */
    private const ENV_NAMES = ['ASSISTANT_LLM_BASE_URL', 'ASSISTANT_LLM_MODEL', 'ASSISTANT_LLM_API_KEY'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // A developer .env populates these, so an unguarded test would read the machine it runs on
        // and pass or fail for reasons unrelated to the code. Saved and restored rather than
        // assumed absent.
        foreach (self::ENV_NAMES as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if (\is_string($value)) {
                putenv(\sprintf('%s=%s', $name, $value));
            }
        }
    }

    public function testStoredSettingsBecomeLlmSettings(): void
    {
        $settings = (new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
            self::PREFIX . 'llmModel' => 'anthropic/claude-sonnet-5',
            self::PREFIX . 'llmApiKey' => 'sk-test',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame('https://openrouter.ai/api', $settings->baseUrl);
        self::assertSame('anthropic/claude-sonnet-5', $settings->model);
        self::assertSame('sk-test', $settings->apiKey);
    }

    public function testTheEnvironmentOverridesStoredConfig(): void
    {
        // Shopware system config has no real secret storage, so the env var is the preferred path
        // and has to actually win — otherwise the advice in config.xml's help text is decorative.
        putenv('ASSISTANT_LLM_MODEL=anthropic/claude-opus-5');

        $settings = (new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
            self::PREFIX . 'llmModel' => 'stored/model',
            self::PREFIX . 'llmApiKey' => 'sk-test',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame('anthropic/claude-opus-5', $settings->model);
    }

    public function testAnIncompleteConfigurationNamesEveryMissingKey(): void
    {
        // Ruling R43: checking only the base URL once meant a half-configured setup attempted a
        // real network call. Naming all of them beats "not configured".
        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('llmModel');

        (new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
        ])))->forSalesChannel(self::CHANNEL);
    }

    public function testWhitespaceOnlyCountsAsMissingRatherThanAsAValue(): void
    {
        $this->expectException(LlmException::class);

        (new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => '   ',
            self::PREFIX . 'llmModel' => 'm',
            self::PREFIX . 'llmApiKey' => 'k',
        ])))->forSalesChannel(self::CHANNEL);
    }

    public function testIsConfiguredAnswersWithoutThrowingSoAShopperGetsAPoliteReply(): void
    {
        // The controller needs this to avoid turning a missing API key into a 500, and the widget
        // needs it to stay hidden on a shop that never configured a model.
        self::assertFalse((new SystemConfigLlmSettings(new FakeSystemConfigService()))->isConfigured(self::CHANNEL));

        self::assertTrue((new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
            self::PREFIX . 'llmModel' => 'm',
            self::PREFIX . 'llmApiKey' => 'k',
        ])))->isConfigured(self::CHANNEL));
    }
}
