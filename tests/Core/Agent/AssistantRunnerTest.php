<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AssistantRunnerTest extends TestCase
{
    private function bundle(
        AssistantConfig $config,
        bool $cartAvailable,
        ?HttpClientInterface $http = null,
    ): AssistantAgentFactory\Bundle {
        return AssistantAgentFactory::create(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'),
            $config,
            $cartAvailable,
            new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
            $http ?? self::forbiddenHttpClient(),
        );
    }

    private function runner(AssistantConfig $config, ?MockHttpClient $http = null): AssistantRunner
    {
        $http ??= self::forbiddenHttpClient();

        return new AssistantRunner($config, $this->bundle($config, cartAvailable: false, http: $http));
    }

    /**
     * @return list<string>
     */
    private function toolNames(AssistantAgentFactory\Bundle $bundle): array
    {
        return array_values(array_map(static fn(Tool $tool): string => $tool->getName(), $bundle->toolbox->getTools()));
    }

    private static function forbiddenHttpClient(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called.');
        });
    }

    public function testReturnsTheGuardMessageWithoutCallingThePlatformWhenKilled(): void
    {
        $http = self::forbiddenHttpClient();
        $runner = $this->runner(new AssistantConfig(killSwitch: true), $http);

        $turn = $runner->run('anything', new MessageBag());

        self::assertSame('error', $turn->outcome);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testDoesNotConstructTheCartToolWhenNoShopperCartExists(): void
    {
        $bundle = $this->bundle(new AssistantConfig(), cartAvailable: false);

        self::assertNotContains('add_to_cart', $this->toolNames($bundle));
    }

    public function testConstructsTheCartToolWhenEnabledAndAvailable(): void
    {
        $bundle = $this->bundle(new AssistantConfig(), cartAvailable: true);

        self::assertContains('add_to_cart', $this->toolNames($bundle));
    }

    public function testDoesNotConstructTheCartToolWhenDisabledEvenIfACartExists(): void
    {
        $bundle = $this->bundle(new AssistantConfig(enableAddToCart: false), cartAvailable: true);

        self::assertNotContains('add_to_cart', $this->toolNames($bundle));
    }
}
