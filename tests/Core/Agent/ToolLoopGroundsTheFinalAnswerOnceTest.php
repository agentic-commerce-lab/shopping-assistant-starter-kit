<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\PlatformFactory;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\GetProductTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A real tool-calling turn, driven through the installed framework over mock HTTP: the tool runs,
 * and grounding is handed the finished answer exactly once.
 *
 * **This file used to ask a different question.** As `OutputProcessorOrderTest`, it asked whether
 * placing {@see GroundingOutputProcessor} before `Toolbox\AgentProcessor` in `outputProcessors`
 * would make grounding "validate a result the tools have not populated yet" — and recorded the
 * finding (Ruling R33) that it could not: that processor resolved every tool call inside its own
 * `processOutput()`, strictly before it re-entered `Agent::call()` recursively, so both orders
 * produced the same text and the same cards. Symfony AI 0.13 removed `AgentProcessor` and moved the
 * tool loop into the {@see Agent} itself, which retires the question rather than answering it: there
 * is no longer a processor to be ordered against, output processors run once, and they run against
 * the final assembled result.
 *
 * **What is kept is the round trip**, because that is the part that earns its place. Everything else
 * about grounding is tested against a hand-built `Output`; this is the only test that puts a real
 * tool, a real toolbox and a real `Agent` on the path a shopper's turn actually takes. It is
 * therefore where the NEXT framework change to that loop shows up first — as it did for this one.
 */
final class ToolLoopGroundsTheFinalAnswerOnceTest extends TestCase
{
    use UsesCatalogFixture;

    /**
     * The two responses one tool-calling turn needs: the model asks for `get_product`, then answers.
     */
    private static function modelSaying(): MockHttpClient
    {
        return new MockHttpClient([
            new MockResponse(json_encode(
                [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call-1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_product',
                                    'arguments' => json_encode(['productId' => 'fx-017'], \JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                ],
                \JSON_THROW_ON_ERROR,
            )),
            new MockResponse(json_encode(
                [
                    'choices' => [['message' => ['content' => 'Here it is: fx-017'], 'finish_reason' => 'stop']],
                ],
                \JSON_THROW_ON_ERROR,
            )),
        ]);
    }

    public function testTheToolRunsBeforeGroundingSeesTheAnswerAndGroundingSeesItOnce(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);
        $config = new AssistantConfig();

        $tool = new GetProductTool(
            $gateway,
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            $renderer,
            $trace,
            $config,
        );

        $http = self::modelSaying();

        $agent = new Agent(
            PlatformFactory::create(new LlmSettings('https://1.1.1.1', 'k', 'gpt-x'), $http),
            'gpt-x',
            outputProcessors: [new GroundingOutputProcessor($renderer, $trace)],
            toolbox: new Toolbox([$tool]),
            maxToolCalls: 5,
        );

        // `getResult()`, not the bare call: since 0.13 the execution is lazy and nothing has run
        // until it is resolved — the same reason AssistantRunner resolves inside its try block.
        $result = $agent->call(new MessageBag(Message::ofUser('find fx-017')))->getResult();

        self::assertInstanceOf(TextResult::class, $result);
        self::assertSame('Here it is: fx-017', $result->getContent());

        // The tool really ran, and it ran before grounding: the card can only be here because
        // GetProductTool registered it with the renderer during the loop.
        self::assertSame(['fx-017'], array_map(static fn($card) => $card->id, $renderer->renderedCards()));
        self::assertSame(2, $http->getRequestsCount());

        $stages = array_map(static fn($event): string => $event->stage, $trace->events());

        self::assertSame(1, \count(array_filter($stages, static fn($s): bool => 'grounding.select' === $s)));
    }
}
