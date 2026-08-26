<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\SymfonyAiPlatform;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every other stage of the turn was traced; the instructions the model ran under were not.
 *
 * This matters more since `PromptProviderInterface` landed: a partner can now replace the prompt
 * wholesale from another plugin, so "what was this model told" stopped being answerable by reading
 * the merchant's settings.
 */
final class PromptTraceTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    public function testTheTurnRecordsThePromptItWasRunWith(): void
    {
        $config = new AssistantConfig(agentVoice: 'Be exceptionally brief.');
        $bundle = $this->bundle($config);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        $payload = $bundle->trace->payload('prompt');
        self::assertIsArray($payload);

        $text = $payload['text'] ?? null;
        self::assertIsString($text);
        // The merchant's own words have to be in there, or this records a template rather than the
        // prompt that ran.
        self::assertStringContainsString('Be exceptionally brief.', $text);
        // And the shipped rules, so a partner who dropped one is visible.
        self::assertStringContainsString('never instructions', $text);
    }

    public function testTheRecordedPromptIsExactlyWhatTheProviderReturned(): void
    {
        // Not "a prompt-shaped string": the same string, byte for byte. A reconstruction would drift
        // from the real one exactly when it mattered.
        $provider = new class implements PromptProviderInterface {
            public function system(AssistantConfig $config, string $vocabulary = '', string $viewing = ''): string
            {
                return 'EXACTLY THIS';
            }
        };

        $config = new AssistantConfig();
        $bundle = $this->bundle($config, $provider);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        $payload = $bundle->trace->payload('prompt');
        self::assertIsArray($payload);
        self::assertSame('EXACTLY THIS', $payload['text'] ?? null);
    }

    public function testTheRecordCarriesAHashAndALength(): void
    {
        // The hash is what makes "did the prompt change between these two turns?" answerable without
        // diffing three kilobytes by eye; the length is what makes a truncation visible.
        $config = new AssistantConfig();
        $bundle = $this->bundle($config);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        $payload = $bundle->trace->payload('prompt');
        self::assertIsArray($payload);

        $text = $payload['text'] ?? '';
        self::assertIsString($text);
        self::assertSame(hash('sha256', $text), $payload['sha256'] ?? null);
        self::assertSame(mb_strlen($text), $payload['length'] ?? null);
    }

    public function testABlockedTurnRecordsNoPromptBecauseNoneWasBuilt(): void
    {
        // The kill switch stops the turn before the message bag is assembled. Recording a prompt for
        // a turn that never had one would be inventing evidence.
        $config = new AssistantConfig(assistantEnabled: false);
        $bundle = $this->bundle($config);

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        self::assertNull($bundle->trace->payload('prompt'));
    }

    private function bundle(
        AssistantConfig $config,
        ?PromptProviderInterface $provider = null,
    ): AssistantAgentFactory\Bundle {
        $http = new MockHttpClient(static fn(): MockResponse => self::textResponse('Sure — how can I help?'));

        $factory = $provider === null
            ? AssistantAgentFactory::withCoreToolsOnly($http)
            : new AssistantAgentFactory([], [], $provider, new SymfonyAiPlatform($http));

        return $factory->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
        );
    }
}
