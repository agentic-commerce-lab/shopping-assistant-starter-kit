<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Storefront;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings;
use Swag\AssistantStarterKit\Storefront\AssistantWidgetExtension;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;

/**
 * Shared setup for the widget extension's tests.
 *
 * Exists because the two subclasses ask different questions — *does the widget render?* and *what
 * does the template get?* — while needing the same environment guard and the same wiring. Repeating
 * either would be the duplication the quality gate is meant to catch.
 */
abstract class AssistantWidgetTestCase extends TestCase
{
    protected const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    protected const PREFIX = 'SwagAssistantStarterKit.config.';

    /** @var list<string> */
    private const ENV_NAMES = ['ASSISTANT_LLM_BASE_URL', 'ASSISTANT_LLM_MODEL', 'ASSISTANT_LLM_API_KEY'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // `isConfigured()` lets the environment override stored config, and a developer .env
        // populates all three. Unguarded, the negative tests would read the machine they run on and
        // pass for the wrong reason. Same guard as SystemConfigLlmSettingsTest.
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

    /**
     * @param array<string, string|int|float|bool|null> $overrides
     *
     * @return array<string, string|int|float|bool|null>
     */
    protected function configured(array $overrides = []): array
    {
        return [
            self::PREFIX . 'llmBaseUrl' => 'https://api.example.test/v1',
            self::PREFIX . 'llmModel' => 'anthropic/claude-sonnet-5',
            self::PREFIX . 'llmApiKey' => 'sk-test',
            ...$overrides,
        ];
    }

    /**
     * The real config services over the existing array-backed fake, rather than doubles of the
     * services themselves: all three are `final readonly`, and going through them means the
     * absent-key behaviour they document is what these assertions actually exercise.
     *
     * @param array<string, string|int|float|bool|null> $values
     */
    protected function extension(array $values): AssistantWidgetExtension
    {
        $config = new FakeSystemConfigService($values);

        return new AssistantWidgetExtension(
            new SystemConfigLlmSettings($config),
            new SystemConfigAssistantConfig($config),
            new SystemConfigWidgetSettings($config),
        );
    }
}
