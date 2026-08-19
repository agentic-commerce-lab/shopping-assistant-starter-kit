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
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The task brief asks for a test that fails if GroundingOutputProcessor is placed
 * before AgentProcessor in `outputProcessors`, on the theory that Grounding would
 * then "validate a result the tools have not populated yet".
 *
 * This test could not be made to fail that way, and this file documents why, with a
 * real (non-mocked) tool-calling round trip through the installed `symfony/ai-agent
 * 0.12.0` `AgentProcessor`: its `processOutput()` resolves a `ToolCallResult` by
 * recursively calling `$this->agent->call()` again — a FULL `Agent::call()`, running
 * through the SAME `outputProcessors` list again for the nested response. Tool
 * execution (and therefore `FactRenderer::registerRetrieved()`) always happens
 * inside `AgentProcessor::processOutput()` itself, strictly before that recursive
 * call, regardless of where `AgentProcessor` sits in the processor list. So by the
 * time any processor — Grounding included — ever sees a genuine `TextResult`, every
 * tool call that produced it has already executed.
 *
 * Swapping the order does not corrupt anything; it only changes how many times
 * Grounding redundantly re-validates the same, already-correct final text (see the
 * task report for the full trace-through and a standalone reproduction script).
 * This test pins that empirical finding down as a permanent regression check: if a
 * future Symfony AI release changes `AgentProcessor`'s recursive design, this test
 * is where that would first show up as the two orders starting to disagree.
 */
final class OutputProcessorOrderTest extends TestCase
{
    /**
     * @return array{0: TextResult|null, 1: FactRenderer}
     */
    private function runTurn(bool $groundingFirst): array
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
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
        $toolbox = new Toolbox([$tool]);
        $toolProcessor = new AgentProcessor($toolbox, maxToolCalls: 5);
        $grounding = new GroundingOutputProcessor($renderer, $trace);

        $http = new MockHttpClient([
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

        $agent = new Agent(
            PlatformFactory::create(new LlmSettings('https://1.1.1.1', 'k', 'gpt-x'), $http),
            'gpt-x',
            inputProcessors: [$toolProcessor],
            outputProcessors: $groundingFirst ? [$grounding, $toolProcessor] : [$toolProcessor, $grounding],
        );

        $result = $agent->call(new MessageBag(Message::ofUser('find fx-017')));

        return [$result instanceof TextResult ? $result : null, $renderer];
    }

    public function testGroundingBeforeOrAfterAgentProcessorProducesTheSameFinalTextAndRenderedCards(): void
    {
        [$correctOrderResult, $correctOrderRenderer] = $this->runTurn(groundingFirst: false);
        [$swappedOrderResult, $swappedOrderRenderer] = $this->runTurn(groundingFirst: true);

        self::assertNotNull($correctOrderResult);
        self::assertNotNull($swappedOrderResult);
        self::assertSame('Here it is: fx-017', $correctOrderResult->getContent());
        self::assertSame($correctOrderResult->getContent(), $swappedOrderResult->getContent());

        self::assertSame(['fx-017'], array_map(static fn($c) => $c->id, $correctOrderRenderer->renderedCards()));
        self::assertSame(
            array_map(static fn($c) => $c->id, $correctOrderRenderer->renderedCards()),
            array_map(static fn($c) => $c->id, $swappedOrderRenderer->renderedCards()),
        );
    }
}
