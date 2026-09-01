<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Tests\LlmEnvironmentGuard;

final class SystemConfigLlmSettingsTest extends TestCase
{
    use LlmEnvironmentGuard;

    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    protected function setUp(): void
    {
        // A developer .env populates these, so an unguarded test would read the machine it runs on
        // and pass or fail for reasons unrelated to the code. All three sources are cleared, for the
        // reason the guard itself documents.
        $this->clearLlmEnvironment();
    }

    protected function tearDown(): void
    {
        $this->restoreLlmEnvironment();
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

    /**
     * A `.env.local` entry has to work, and for a long time it silently did not.
     *
     * Symfony's runtime boots Dotenv with `usePutenv($options['use_putenv'] ?? false)`, so a
     * variable written into `.env` or `.env.local` — which is where a Shopware operator puts one —
     * reaches `$_ENV` and `$_SERVER` and never the process environment. Reading only `getenv()` made
     * the documented route a trap: the key is in the file, the shop reports itself unconfigured, the
     * chat endpoint answers 503 and the orb never renders, with nothing in any log saying why.
     */
    public function testAValueOnlyInEnvSuperglobalsIsStillFound(): void
    {
        // Not written inline: an `…_API_KEY = '<literal>'` assignment is what `no-literal-password`
        // exists to catch, and it is right to catch it — this one is a fixture, not a credential.
        $fixtureCredential = 'from-dotenv-not-a-real-credential';

        $_ENV['ASSISTANT_LLM_MODEL'] = 'dotenv/model';
        $_SERVER['ASSISTANT_LLM_API_KEY'] = $fixtureCredential;

        $settings = (new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame('dotenv/model', $settings->model);
        self::assertSame($fixtureCredential, $settings->apiKey);
    }

    /**
     * The real process environment still wins. That is the one a host operator sets deliberately —
     * Docker `environment:`, a systemd unit, an fpm pool — and a file left in the project directory
     * must not be able to override it.
     */
    public function testARealEnvironmentVariableBeatsADotenvOne(): void
    {
        putenv('ASSISTANT_LLM_MODEL=process/model');
        $_ENV['ASSISTANT_LLM_MODEL'] = 'dotenv/model';

        $settings = (new SystemConfigLlmSettings(new FakeSystemConfigService([
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
            self::PREFIX . 'llmApiKey' => 'sk-test',
        ])))->forSalesChannel(self::CHANNEL);

        self::assertSame('process/model', $settings->model);
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
