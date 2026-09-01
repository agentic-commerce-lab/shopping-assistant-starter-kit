<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Agent\IncompleteTurnMessage;
use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AssistantRunnerTest extends TestCase
{
    use UsesCatalogFixture;

    private function bundle(
        AssistantConfig $config,
        bool $cartAvailable,
        ?HttpClientInterface $http = null,
    ): AssistantAgentFactory\Bundle {
        // The client now belongs to the platform rather than to create(): same default as before,
        // a client that throws if anything reaches the network.
        return AssistantAgentFactory::withCoreToolsOnly($http ?? self::forbiddenHttpClient())->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            $cartAvailable,
            new LlmSettings('https://example.invalid', 'test-key', 'gpt-x'),
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
        $runner = $this->runner(new AssistantConfig(assistantEnabled: false), $http);

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

    /**
     * Defect 1 (fix-run3-brief): a model that keeps requesting tool calls past
     * `maxToolCallsPerTurn` makes {@see \Swag\AssistantStarterKit\Core\Agent\BoundedToolbox}
     * throw {@see \Symfony\AI\Agent\Exception\MaxIterationsExceededException}. Before this
     * fix that exception escaped {@see AssistantRunner::run()} uncaught and killed the
     * turn. Scripts a transcript that requests a tool call on every round, past a
     * deliberately low cap (2), so the third dispatched tool call is the one that
     * exceeds it — same MockHttpClient/tool_calls response shape as
     * {@see \Swag\AssistantStarterKit\Tests\Eval\JourneyAttemptMultiTurnTest}.
     */
    public function testDegradesInsteadOfThrowingWhenTheToolCallCapIsExceeded(): void
    {
        $toolCallResponse = static fn(string $callId): MockResponse => new MockResponse(json_encode(
            [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => $callId,
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_products',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ],
            \JSON_THROW_ON_ERROR,
        ));

        // Three rounds requesting a tool call, each one dispatched through
        // BoundedToolbox: the cap (2) is exceeded on the third dispatch, so no fourth
        // platform response is ever requested.
        $http = new MockHttpClient([
            $toolCallResponse('call-1'),
            $toolCallResponse('call-2'),
            $toolCallResponse('call-3'),
        ]);

        $config = new AssistantConfig(maxToolCallsPerTurn: 2);
        // The transcript client goes to the platform, which is where the HTTP client now lives —
        // unlike bundle()'s refusing client, this test's whole point is that the platform IS called.
        $bundle = AssistantAgentFactory::withCoreToolsOnly($http)->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            // A resolvable public host, unlike bundle()'s 'https://example.invalid' —
            // this test's transcript actually reaches the (mocked) platform, so it must
            // clear ValidatingHttpClient's SSRF guard; same host JourneyAttemptMultiTurnTest
            // uses for the same reason.
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
        );
        $runner = new AssistantRunner($config, $bundle);

        $turn = $runner->run('find me something', new MessageBag());

        self::assertSame(TurnOutcomeResolver::TOOL_LIMIT_EXCEEDED, $turn->outcome);

        // The message moved out of a constant on this class and into IncompleteTurnMessage, which picks
        // it by language and by whether cards accompany it. Cards DO survive this turn (asserted
        // below), so the expected variant is the one that says so — before, the sentence claimed
        // nothing about them and they read as the answer.
        self::assertSame(IncompleteTurnMessage::for($config->defaultReplyLanguage, hasCards: true), $turn->prose);

        // The first two dispatched tool calls (search_products with no filters) really
        // did retrieve products from the fixture catalog before the cap was hit — those
        // cards are grounded and must survive on the returned turn rather than being
        // thrown away.
        self::assertNotSame([], $turn->cards);

        $stages = $bundle->trace->stages();
        self::assertContains('turn.tool_limit_exceeded', $stages);
        self::assertContains('turn.end', $stages);

        $turnEnd = $bundle->trace->payload('turn.end');
        self::assertNotNull($turnEnd);
        self::assertSame(TurnOutcomeResolver::TOOL_LIMIT_EXCEEDED, $turnEnd['outcome'] ?? null);
    }
}
