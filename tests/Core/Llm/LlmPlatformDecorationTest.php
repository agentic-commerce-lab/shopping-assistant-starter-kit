<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Llm;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmPlatformInterface;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\PlatformFactory;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPromptProvider;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Symfony AI ships platform bridges for dozens of providers, and until `LlmPlatformInterface` existed
 * this plugin could reach none of them: {@see PlatformFactory::create()} is static and pins the
 * generic OpenAI-compatible one.
 *
 * The claim under test is that a replacement is *consulted*, not merely accepted by the constructor.
 */
final class LlmPlatformDecorationTest extends TestCase
{
    use UsesCatalogFixture;

    public function testAReplacedPlatformIsTheOneTheAgentIsBuiltWith(): void
    {
        $platform = new class implements LlmPlatformInterface {
            public int $calls = 0;

            public ?LlmSettings $lastSettings = null;

            public function of(LlmSettings $settings): PlatformInterface
            {
                $this->calls++;
                $this->lastSettings = $settings;

                return PlatformFactory::create($settings, new MockHttpClient());
            }
        };

        $settings = new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x');

        (new AssistantAgentFactory([], [], new SystemPromptProvider(), $platform))->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            new AssistantConfig(),
            cartAvailable: false,
            llm: $settings,
        );

        self::assertSame(1, $platform->calls, 'the factory must ask the injected platform, not a static');
        // Per sales channel, not per install: one shop can point two channels at two models, which is
        // why the settings arrive per call rather than being constructor state.
        self::assertSame($settings, $platform->lastSettings);
    }
}
