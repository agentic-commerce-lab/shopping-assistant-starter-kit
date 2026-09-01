<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Storefront;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings;
use Swag\AssistantStarterKit\Core\Context\ContextStorageKey;
use Swag\AssistantStarterKit\Core\Context\ShoppingContextResolver;
use Swag\AssistantStarterKit\Storefront\AssistantWidgetExtension;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;
use Swag\AssistantStarterKit\Tests\LlmEnvironmentGuard;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared setup for the widget extension's tests.
 *
 * Exists because the two subclasses ask different questions — *does the widget render?* and *what
 * does the template get?* — while needing the same environment guard and the same wiring. Repeating
 * either would be the duplication the quality gate is meant to catch.
 */
abstract class AssistantWidgetTestCase extends TestCase
{
    use LlmEnvironmentGuard;

    protected const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    protected const PREFIX = 'SwagAssistantStarterKit.config.';

    protected function setUp(): void
    {
        // `isConfigured()` lets the environment override stored config, and a developer .env
        // populates all three names in all three sources. Unguarded, the negative tests would read
        // the machine they run on and pass for the wrong reason.
        $this->clearLlmEnvironment();
    }

    protected function tearDown(): void
    {
        $this->restoreLlmEnvironment();
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
     * The resolver is request-less on purpose: nothing in `AssistantWidgetGateTest` or
     * `AssistantWidgetTemplateDataTest` calls `contextKey()`, so a provider with no request is enough
     * to satisfy the constructor — {@see AssistantWidgetContextKeyTest} builds its own with a real
     * sales-channel context where the resolved key is the point of the test.
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
            new ShoppingContextResolver(new SalesChannelContextProvider(new RequestStack())),
            new ContextStorageKey('test-kernel-secret'),
        );
    }
}
