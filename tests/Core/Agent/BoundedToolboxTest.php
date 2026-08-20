<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\BoundedToolbox;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Finding C2 lives here, proven against the REAL vendor {@see Toolbox} — not a stub —
 * so these tests exercise the exact wrapping/unwrapping behaviour production code
 * depends on. Finding I2's other shapes ({@see \Symfony\Component\Serializer\Exception\NotNormalizableValueException},
 * a `\TypeError` from argument dispatch, and the boundary that still rethrows a genuine
 * server fault) live in {@see BoundedToolboxArgumentRejectionTest} — split out once
 * adding them here pushed this class past mago's too-many-methods threshold, same
 * reasoning as the FactRendererTest split.
 */
final class BoundedToolboxTest extends TestCase
{
    private function toolbox(TraceRecorder $trace, int $max): BoundedToolbox
    {
        return new BoundedToolbox(new Toolbox([new EscalateTool($trace)]), $max, $trace);
    }

    public function testDelegatesToolMetadataToTheInnerToolbox(): void
    {
        $toolbox = $this->toolbox(new TraceRecorder(), 5);

        self::assertSame(['escalate'], array_map(static fn($tool) => $tool->getName(), $toolbox->getTools()));
    }

    /**
     * Finding C2 (RED before the fix): AgentProcessor's own `maxToolCalls` constructor
     * argument never actually bounds anything — its `$iterations` counter is a local
     * that resets on every recursive re-entry of `handleToolCallsCallback()`, one per
     * tool round, so a model could call a tool 20 times against a cap of 3 with no
     * exception at all. This class's own `$calls` counter lives on the instance, which
     * backs every recursion level for the whole request, so it is what actually
     * enforces the cap.
     */
    public function testThrowsOnceMoreCallsHappenThanTheConfiguredCap(): void
    {
        $toolbox = $this->toolbox(new TraceRecorder(), 2);

        $toolbox->execute(new ToolCall('call-1', 'escalate', ['reason' => 'a']));
        $toolbox->execute(new ToolCall('call-2', 'escalate', ['reason' => 'b']));

        $this->expectException(MaxIterationsExceededException::class);

        $toolbox->execute(new ToolCall('call-3', 'escalate', ['reason' => 'c']));
    }

    public function testDoesNotThrowWhenCallsStayAtOrBelowTheCap(): void
    {
        $toolbox = $this->toolbox(new TraceRecorder(), 1);

        $result = $toolbox->execute(new ToolCall('call-1', 'escalate', ['reason' => 'a']));

        self::assertIsArray($result->getResult());
    }

    public function testRecordsAUniformDispatchEventForEveryToolCall(): void
    {
        $trace = new TraceRecorder();
        $toolbox = $this->toolbox($trace, 5);

        $toolbox->execute(new ToolCall('call-1', 'escalate', ['reason' => 'a']));

        $dispatchEvents = array_values(array_filter(
            $trace->events(),
            static fn($event): bool => (
                'tool.call' === $event->stage
                && 'dispatch' === ($event->payload['stage'] ?? null)
            ),
        ));

        self::assertCount(1, $dispatchEvents);
        self::assertSame('escalate', $dispatchEvents[0]->payload['name'] ?? null);
    }

    /**
     * Finding I2 (RED before the fix): `Toolbox::execute()` wraps ANY `\Throwable` a
     * tool raises — including our own {@see \Swag\AssistantStarterKit\Core\Tool\ToolArgumentException}
     * from {@see \Swag\AssistantStarterKit\Core\Tool\Guard} — into a
     * `ToolExecutionException`, which propagated uncaught through `AgentProcessor` and
     * `AssistantRunner::run()` and aborted the whole turn. An oversized argument is the
     * single most likely model mistake, not an adversarial one, and this is what turns
     * it back into something the model can read and correct on the next round.
     */
    public function testUnwrapsAToolArgumentExceptionIntoARetryableNoteInsteadOfThrowing(): void
    {
        $toolbox = $this->toolbox(new TraceRecorder(), 5);

        $result = $toolbox->execute(new ToolCall('call-1', 'escalate', ['reason' => str_repeat('x', 600)]));

        $payload = $result->getResult();
        self::assertIsArray($payload);
        self::assertArrayHasKey('note', $payload);
        $note = $payload['note'];
        self::assertIsString($note);
        self::assertStringContainsString('reason', $note);
    }

    public function testAnUnwrappedArgumentErrorStillCountsAgainstTheCap(): void
    {
        $toolbox = $this->toolbox(new TraceRecorder(), 1);

        $toolbox->execute(new ToolCall('call-1', 'escalate', ['reason' => str_repeat('x', 600)]));

        $this->expectException(MaxIterationsExceededException::class);

        $toolbox->execute(new ToolCall('call-2', 'escalate', ['reason' => 'fine']));
    }

    public function testStartTurnGivesTheNextTurnItsOwnBudget(): void
    {
        $trace = new TraceRecorder();
        // Production builds a fresh bundle per HTTP request, so every shopper message gets its own
        // budget — which is what `maxToolCallsPerTurn` promises and what config.xml's help text says.
        // The eval harness reuses one bundle across a journey's turns, and the shared counter made the
        // bound per CONVERSATION: `cart_add` spent ~3 calls finding the variant and could not afford
        // to add it, failing 6 of 6 runs on a limit the endpoint would have refreshed (ruling R84).
        $toolbox = $this->toolbox($trace, 2);

        $toolbox->execute(new ToolCall('1', 'escalate', ['reason' => 'one']));
        $toolbox->execute(new ToolCall('2', 'escalate', ['reason' => 'two']));

        $toolbox->startTurn();

        // Without the reset this third call exceeds the budget of 2 and throws.
        $result = $toolbox->execute(new ToolCall('3', 'escalate', ['reason' => 'three']));

        self::assertNotNull($result);
    }
}
