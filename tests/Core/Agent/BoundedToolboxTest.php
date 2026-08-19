<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\BoundedToolbox;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

/**
 * Findings C2 and I2 both live in {@see BoundedToolbox}, so both are proven here
 * against the REAL vendor {@see Toolbox} — not a stub — so these tests exercise the
 * exact wrapping/unwrapping behaviour production code depends on.
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

    /**
     * Pilot blocker (RED before the fix): a malformed tool-call argument makes Symfony
     * AI's own argument denormalization throw {@see NotNormalizableValueException}
     * *before* our tool code ever runs. That class does not implement
     * `ToolExecutionExceptionInterface`, only the Serializer component's own
     * {@see \Symfony\Component\Serializer\Exception\ExceptionInterface}, so — same as
     * any other tool failure per finding I2 — it reached this class uncaught and killed
     * the whole turn instead of becoming feedback the model could act on.
     *
     * Here the inner {@see ToolboxInterface} is a double that throws the serializer
     * exception directly from `execute()`, per this project's brief, to exercise the
     * disjoint `catch (SerializerExceptionInterface)` block on its own.
     */
    public function testUnwrapsASerializerExceptionThrownDirectlyIntoARetryableNoteInsteadOfThrowing(): void
    {
        $trace = new TraceRecorder();
        $inner = new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                throw new NotNormalizableValueException('Expected int, string given.');
            }
        };

        $toolbox = new BoundedToolbox($inner, 5, $trace);

        $result = $toolbox->execute(new ToolCall('call-1', 'search_products', ['limit' => 'ten']));

        $payload = $result->getResult();
        self::assertIsArray($payload);
        self::assertArrayHasKey('note', $payload);
        $note = $payload['note'];
        self::assertIsString($note);
        self::assertStringContainsString('Expected int, string given.', $note);

        $rejectedEvents = array_values(array_filter(
            $trace->events(),
            static fn($event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertCount(1, $rejectedEvents);
        self::assertSame('search_products', $rejectedEvents[0]->payload['name'] ?? null);
        self::assertSame('Expected int, string given.', $rejectedEvents[0]->payload['reason'] ?? null);
    }

    /**
     * The shape the REAL vendor `Toolbox::execute()` actually throws in production: it
     * catches `\Throwable` for anything that is not a `ToolExecutionExceptionInterface`
     * — `NotNormalizableValueException` included — and wraps it into a
     * `ToolExecutionException` with the original as `getPrevious()`, exactly like any
     * other tool failure. Confirmed by reading
     * `vendor/symfony/ai-agent/src/Toolbox/Toolbox.php` and reproducing it against the
     * installed package with a throwaway backed-enum-typed tool argument — the
     * brief's own stack trace (`NotNormalizableValueException` ... `BoundedToolbox.php`
     * ... `AgentProcessor.php`) is the merged view of exactly this exception chain, not
     * a raw `NotNormalizableValueException` reaching this class directly. Without the
     * `$previous instanceof SerializerExceptionInterface` branch inside the existing
     * `catch (ToolExecutionException)` block, the previous test alone would not close
     * the reported defect: production never throws the serializer exception unwrapped.
     */
    public function testUnwrapsASerializerExceptionWrappedInToolExecutionExceptionIntoARetryableNote(): void
    {
        $trace = new TraceRecorder();
        $toolCall = new ToolCall('call-1', 'search_products', ['limit' => 'ten']);
        $previous = new NotNormalizableValueException('The data must belong to a backed enumeration of type Foo.');

        $inner = new class($toolCall, $previous) implements ToolboxInterface {
            public function __construct(
                private readonly ToolCall $toolCall,
                private readonly NotNormalizableValueException $previous,
            ) {}

            public function getTools(): array
            {
                return [];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                throw ToolExecutionException::executionFailed($this->toolCall, $this->previous);
            }
        };

        $toolbox = new BoundedToolbox($inner, 5, $trace);

        $result = $toolbox->execute($toolCall);

        $payload = $result->getResult();
        self::assertIsArray($payload);
        self::assertArrayHasKey('note', $payload);
        $note = $payload['note'];
        self::assertIsString($note);
        self::assertStringContainsString('backed enumeration', $note);

        $rejectedEvents = array_values(array_filter(
            $trace->events(),
            static fn($event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertCount(1, $rejectedEvents);
        self::assertSame('search_products', $rejectedEvents[0]->payload['name'] ?? null);
    }
}
