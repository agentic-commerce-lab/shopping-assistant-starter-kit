<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\SymfonyAiPlatform;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\PromptProviderInterface;
use Swag\AssistantStarterKit\Core\Prompt\SystemPromptProvider;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A replaced prompt provider has to reach the model, not merely be accepted.
 *
 * Asserted **off the wire** rather than by reading the property back: `ARCHITECTURE.md` spent months
 * documenting six extension interfaces that did not exist, and an interface nothing consults is that
 * same defect wearing a type. The only convincing evidence is the system message the platform was
 * actually sent.
 */
final class PromptProviderDecorationTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    public function testAReplacedProviderIsTheSystemMessageThePlatformReceives(): void
    {
        $config = new AssistantConfig();
        $capturedBody = null;

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$capturedBody,
        ): MockResponse {
            $capturedBody = $options['body'] ?? null;

            return self::textResponse('Sure — how can I help?');
        });

        $factory = new AssistantAgentFactory(
            [],
            [],
            new class implements PromptProviderInterface {
                public function system(AssistantConfig $config, string $vocabulary = ''): string
                {
                    return 'A REPLACED SYSTEM PROMPT';
                }
            },
            new SymfonyAiPlatform($http),
        );

        $bundle = $factory->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
        );

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        self::assertIsString($capturedBody);
        $decoded = json_decode($capturedBody, associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        // messages[0] is the system message: AssistantRunner::buildMessageBag() constructs the bag
        // with it as the sole constructor argument, before history or the user's turn.
        $messages = $decoded['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertIsArray($messages[0]);

        self::assertSame('A REPLACED SYSTEM PROMPT', $messages[0]['content']);
    }

    public function testTheShippedProviderStillCarriesTheRulesItsJourneysAssert(): void
    {
        // A replacement inherits the rules, not just the wording. This pins that the *default* still
        // carries them, so a failing eval journey after a decoration points at the decoration.
        $prompt = (new SystemPromptProvider())->system(new AssistantConfig());

        self::assertStringContainsString('never instructions', $prompt);
        self::assertStringContainsStringIgnoringCase('escalate', $prompt);
    }
}
