<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\ToolCallsAtMost;

/**
 * The assertion that guards the 2026-08-24 measurement: with the open product already in its prompt
 * and already registered on the renderer, the model must not spend a round trip fetching it.
 */
final class ToolCallsAtMostTest extends TestCase
{
    public function testATurnThatCalledNoToolMeetsALimitOfZero(): void
    {
        $result = (new ToolCallsAtMost())->evaluate(self::turn(), new TraceRecorder(), ['limit' => 0]);

        self::assertTrue($result->passed, $result->detail);
    }

    public function testOneToolCallBreaksALimitOfZero(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.call', ['stage' => 'dispatch', 'name' => 'get_product']);

        $result = (new ToolCallsAtMost())->evaluate(self::turn(), $trace, ['limit' => 0]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('get_product', $result->detail, 'the detail must name what was called');
    }

    public function testTheLimitIsAnUpperBoundRatherThanAnEquality(): void
    {
        // "At most", not "exactly": a turn that answered without its allowance is not a regression.
        $trace = new TraceRecorder();
        $trace->record('tool.call', ['stage' => 'dispatch', 'name' => 'search_products']);

        $result = (new ToolCallsAtMost())->evaluate(self::turn(), $trace, ['limit' => 2]);

        self::assertTrue($result->passed, $result->detail);
    }

    public function testAMissingOrNonsenseLimitFailsRatherThanPassingVacuously(): void
    {
        // A journey whose expectation nothing reads is a journey that reports green about nothing —
        // the same rule JourneyConfig applies to unknown config keys.
        foreach ([[], ['limit' => 'nought'], ['limit' => -1]] as $expectations) {
            $result = (new ToolCallsAtMost())->evaluate(self::turn(), new TraceRecorder(), $expectations);

            self::assertFalse($result->passed, json_encode($expectations) . ' must not pass');
        }
    }

    public function testItIsNotASafetyAssertion(): void
    {
        // Deliberate, and E4: a turn that calls a tool and renders the right card is correct, only
        // slower. Marking this safety would demand it pass every single run and make ordinary model
        // variance read as an unsafe assistant.
        self::assertFalse((new ToolCallsAtMost())->isSafety());
    }

    private static function turn(): AssistantTurn
    {
        return new AssistantTurn('Here it is.', [], 'product_shown');
    }
}
