<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\NoRejectedToolArguments;
use Swag\AssistantStarterKit\Eval\Assertion\ToolCallsAtLeast;

/**
 * The two assertions that let a red journey name its cause.
 *
 * `renders_at_least` already caught the failure measured on 2026-09-02 with `openai/gpt-5-mini`;
 * what it could not say is whether the model never searched, searched and found nothing, or had its
 * arguments rejected. Those have three different fixes.
 */
#[CoversClass(ToolCallsAtLeast::class)]
#[CoversClass(NoRejectedToolArguments::class)]
final class ToolCallsAtLeastTest extends TestCase
{
    public function testATurnThatNeverLookedAnythingUpFails(): void
    {
        $result = (new ToolCallsAtLeast())->evaluate($this->turn(), new TraceRecorder(), ['limit' => 1]);

        self::assertFalse($result->passed);
        // The wording is the diagnosis, so it is pinned.
        self::assertStringContainsString('no tool call at all', $result->detail);
    }

    public function testOneCallMeetsAFloorOfOne(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.call', ['stage' => 'dispatch', 'name' => 'search_products']);

        self::assertTrue((new ToolCallsAtLeast())->evaluate($this->turn(), $trace, ['limit' => 1])->passed);
    }

    public function testAMissingOrZeroLimitIsRejectedRatherThanReportingGreen(): void
    {
        $assertion = new ToolCallsAtLeast();

        self::assertFalse($assertion->evaluate($this->turn(), new TraceRecorder(), [])->passed);
        self::assertFalse($assertion->evaluate($this->turn(), new TraceRecorder(), ['limit' => 0])->passed);
    }

    /**
     * Safety, so it must hold in every run: an answer about products that never consulted the
     * catalogue is the failure this project exists to prevent.
     */
    public function testItIsASafetyAssertion(): void
    {
        self::assertTrue((new ToolCallsAtLeast())->isSafety());
        // A single recovered rejection costs a tool call and nothing else, so that one is quality.
        self::assertFalse((new NoRejectedToolArguments())->isSafety());
    }

    public function testACleanTurnHasNoRejections(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.call', ['stage' => 'dispatch', 'name' => 'search_products']);

        self::assertTrue((new NoRejectedToolArguments())->evaluate($this->turn(), $trace, [])->passed);
    }

    public function testARejectionIsReportedWithItsToolAndItsReason(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.arguments.rejected', [
            'name' => 'search_products',
            'reason' => 'Argument "terms" accepts at most 3 search terms in total.',
        ]);

        $result = (new NoRejectedToolArguments())->evaluate($this->turn(), $trace, []);

        self::assertFalse($result->passed);
        self::assertStringContainsString('search_products', $result->detail);
        self::assertStringContainsString('at most 3 search terms', $result->detail);
    }

    private function turn(): AssistantTurn
    {
        return new AssistantTurn('some prose', [], 'no_result');
    }
}
