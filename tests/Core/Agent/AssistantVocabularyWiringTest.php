<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\CatalogVocabulary;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Wiring test for the catalog vocabulary feature, kept in its own small class
 * (rather than folded into {@see AssistantRunnerTest} or {@see AssistantAgentFactoryTest})
 * purely to keep each test class's own aggregate cyclomatic complexity under this
 * project's mago threshold — the same reasoning documented on
 * {@see \Swag\AssistantStarterKit\Eval\JourneyAttempt} for splitting out of
 * {@see \Swag\AssistantStarterKit\Eval\JourneyRunner}.
 *
 * {@see AssistantAgentFactory::create()} probes facets and renders {@see CatalogVocabulary}
 * once per request, carries the string on {@see AssistantAgentFactory\Bundle::$vocabulary},
 * and {@see AssistantRunner::buildMessageBag()} passes it into
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt::build()} as the FIRST message
 * of the bag (that method constructs the {@see MessageBag} with the system message as its
 * sole constructor argument, before any history or user message is appended) — this
 * reads `messages[0]` directly rather than searching, for exactly that reason. This test
 * asserts both ends of the wiring: the rendered vocabulary text actually reaches the
 * system message the platform receives, and `vocabulary.render` is recorded in the trace
 * with non-zero counts — not merely that some vocabulary-shaped string exists somewhere.
 */
final class AssistantVocabularyWiringTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    public function testVocabularyReachesTheSystemMessageAndIsRecordedInTheTrace(): void
    {
        $config = new AssistantConfig();
        $capturedBody = null;

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$capturedBody,
        ): MockResponse {
            $capturedBody = $options['body'] ?? null;

            return self::textResponse('Sure — how can I help?');
        });

        // The capturing client goes to the platform: this test reads the system message off the wire,
        // so the platform must actually be called.
        $bundle = AssistantAgentFactory::withCoreToolsOnly($http)->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
        );

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        self::assertIsString($capturedBody);

        $decoded = json_decode($capturedBody, associative: true, flags: \JSON_THROW_ON_ERROR);
        $systemContent = $decoded['messages'][0]['content'] ?? null;
        self::assertIsString($systemContent);

        $expectedVocabulary = CatalogVocabulary::render(FixtureCommerceGateway::fromFile(
            self::catalogFixturePath(),
        )->facets($config->scope));

        self::assertNotSame('', $expectedVocabulary, 'The fixture catalog must actually produce a vocabulary block.');
        self::assertStringContainsString($expectedVocabulary, $systemContent);
        self::assertSame($expectedVocabulary, $bundle->vocabulary);

        $vocabularyPayload = $bundle->trace->payload('vocabulary.render');
        self::assertNotNull($vocabularyPayload);
        self::assertArrayHasKey('fieldCount', $vocabularyPayload);
        self::assertArrayHasKey('valueCount', $vocabularyPayload);
        self::assertArrayHasKey('truncated', $vocabularyPayload);
        self::assertGreaterThan(0, $vocabularyPayload['fieldCount'] ?? 0);
        self::assertGreaterThan(0, $vocabularyPayload['valueCount'] ?? 0);
    }
}
