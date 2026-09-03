<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\ExplicitEmptyToolSchema;
use Swag\AssistantStarterKit\Core\Agent\WholeNumberToolArguments;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * An argument-less tool call, driven through the toolbox the agent actually uses.
 *
 * The unit test beside `GoToCheckoutTool` calls `__invoke()` directly, which proves nothing about
 * the two layers between a model's tool call and that method: `WholeNumberToolArguments` reflects
 * the method's float parameters, and `ToolCallArgumentResolver` walks its parameters looking for
 * ones the call did not supply. Both are loops over a list that is empty here, and "the loop does
 * not run" is a claim worth an assertion rather than a reading.
 *
 * A model may also send `{}`, no `arguments` key, or arguments nobody asked for. All three have to
 * reach the same result.
 */
final class ZeroArgumentToolCallTest extends TestCase
{
    public function testTheToolboxExecutesACallThatCarriesNoArguments(): void
    {
        $result = self::execute(new ToolCall('call-1', 'go_to_checkout', []));

        self::assertSame(true, $result['empty']);
    }

    public function testAFilledCartIsReportedThroughTheSamePath(): void
    {
        $gateway = self::gateway();
        $gateway->addToCart('fx-001', 1);

        $toolbox = new Toolbox(
            [new GoToCheckoutTool($gateway, new TraceRecorder())],
            new ExplicitEmptyToolSchema(),
            new WholeNumberToolArguments(),
        );

        /** @var array{empty: bool, note: string} $result */
        $result = $toolbox->execute(new ToolCall('call-1', 'go_to_checkout', []))->getResult();

        self::assertFalse($result['empty']);
        self::assertStringContainsString('link follows', $result['note']);
    }

    public function testArgumentsNobodyAskedForAreIgnoredRatherThanFatal(): void
    {
        // A model that has been handed an empty schema can still invent a key, and
        // `$tool->{$method}(...$arguments)` on a spread containing an unknown name is a TypeError
        // — so the resolver dropping it is what keeps that from ending the turn.
        $result = self::execute(new ToolCall('call-1', 'go_to_checkout', ['confirm' => true, 'reason' => 'pay']));

        self::assertSame(true, $result['empty']);
    }

    public function testTheToolIsFoundByTheNameTheSchemaAdvertises(): void
    {
        $toolbox = new Toolbox(
            [new GoToCheckoutTool(self::gateway(), new TraceRecorder())],
            new ExplicitEmptyToolSchema(),
            new WholeNumberToolArguments(),
        );

        $names = [];
        foreach ($toolbox->getTools() as $tool) {
            self::assertInstanceOf(Tool::class, $tool);
            $names[] = $tool->getName();
        }

        self::assertSame(['go_to_checkout'], $names);
    }

    /**
     * @return array{empty: bool, note: string}
     */
    private static function execute(ToolCall $call): array
    {
        $toolbox = new Toolbox(
            [new GoToCheckoutTool(self::gateway(), new TraceRecorder())],
            new ExplicitEmptyToolSchema(),
            new WholeNumberToolArguments(),
        );

        /** @var array{empty: bool, note: string} $result */
        $result = $toolbox->execute($call)->getResult();

        return $result;
    }

    private static function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }
}
