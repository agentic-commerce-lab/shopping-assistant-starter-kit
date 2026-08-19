<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\BoundedToolbox;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

/**
 * Split out of {@see BoundedToolboxTest} once these pushed its method count past
 * mago's too-many-methods threshold (same reasoning as the FactRendererTest split).
 * {@see BoundedToolboxTest} keeps finding C2 (the call cap), the dispatch-trace test,
 * and the original `ToolArgumentException` unwrap that predates this split; every test
 * here is about the REST of finding I2 — which shapes of
 * `ToolExecutionException::getPrevious()` {@see BoundedToolbox::execute()} unwraps
 * into a retryable `['note' => …]` result, versus rethrows as a genuine server fault.
 *
 * `toolboxWrapping()` builds an inner {@see ToolboxInterface} double that throws
 * `ToolExecutionException::executionFailed($toolCall, $previous)` — the exact shape
 * the REAL vendor {@see Toolbox} produces for any tool failure that is not a
 * `ToolExecutionExceptionInterface` itself, confirmed by reading
 * `vendor/symfony/ai-agent/src/Toolbox/Toolbox.php` and reproducing it against the
 * installed package. Only the one test proving the disjoint
 * `catch (SerializerExceptionInterface)` branch builds its own double, since that one
 * exercises a `NotNormalizableValueException` thrown UNWRAPPED — not reachable through
 * the real vendor `Toolbox` today, but kept for a toolbox that might throw it directly.
 */
final class BoundedToolboxArgumentRejectionTest extends TestCase
{
    private function toolboxWrapping(ToolCall $toolCall, \Throwable $previous, TraceRecorder $trace): BoundedToolbox
    {
        $inner = new class($toolCall, $previous) implements ToolboxInterface {
            public function __construct(
                private readonly ToolCall $toolCall,
                private readonly \Throwable $previous,
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

        return new BoundedToolbox($inner, 5, $trace);
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
        $toolbox = $this->toolboxWrapping(
            $toolCall,
            new NotNormalizableValueException('The data must belong to a backed enumeration of type Foo.'),
            $trace,
        );

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

    /**
     * The coordinator-identified gap this test closes: our tools' actual parameter
     * shapes (`?array $options` and similar) never trip the Serializer at all — none of
     * the denormalizer's registered normalizers claim to support a bare `array` type, so
     * a model-supplied scalar sails through denormalization unchanged and only fails
     * where the resolved arguments are spread into the tool call, as a native
     * `\TypeError`. That `\TypeError` does not implement `ToolExecutionExceptionInterface`
     * either, so the real vendor `Toolbox::execute()` wraps it into a
     * `ToolExecutionException` the exact same way as the Serializer case.
     *
     * Runs through the REAL vendor {@see Toolbox}, not a fabricated double: `getTrace()`
     * is captured at the point a `\Throwable` is constructed, so a double manually
     * building a `\TypeError` cannot produce a trace whose first frame genuinely sits
     * inside `Toolbox`'s own file — only a real dispatch through the real class can, and
     * that authenticity is the entire point of this test.
     */
    public function testUnwrapsATypeErrorFromArgumentDispatchWrappedInToolExecutionExceptionIntoARetryableNote(): void
    {
        $trace = new TraceRecorder();
        $tool = new
            #[AsTool(name: 'dispatch_mismatch', description: 'test fixture')]
            class {
                public function __invoke(?array $options = null): array
                {
                    return ['ok' => true];
                }
            };
        $toolbox = new BoundedToolbox(new Toolbox([$tool]), 5, $trace);

        $result = $toolbox->execute(new ToolCall('call-1', 'dispatch_mismatch', ['options' => 'Blue']));

        $payload = $result->getResult();
        self::assertIsArray($payload);
        self::assertArrayHasKey('note', $payload);
        $note = $payload['note'];
        self::assertIsString($note);
        self::assertStringContainsString('must be of type ?array', $note);

        $rejectedEvents = array_values(array_filter(
            $trace->events(),
            static fn($event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertCount(1, $rejectedEvents);
        self::assertSame('dispatch_mismatch', $rejectedEvents[0]->payload['name'] ?? null);
    }

    /**
     * The boundary {@see BoundedToolbox::isArgumentDispatchTypeError()} exists to draw:
     * a `\TypeError` raised from inside a tool's OWN body (a wrong-typed argument to some
     * collaborator the tool calls, unrelated to Symfony AI's own argument dispatch) must
     * still propagate as a genuine server fault, not become a note the model shrugs off.
     * Real dispatch again, for the same reason as the previous test: the tool's `__invoke()`
     * calls a strictly-typed private helper with a bad argument, so `getTrace()[0]['file']`
     * genuinely lands inside the tool's own (anonymous class) file, never `Toolbox`'s.
     */
    public function testRethrowsAToolExecutionExceptionWrappingATypeErrorNotFromArgumentDispatch(): void
    {
        $tool = new
            #[AsTool(name: 'body_failure', description: 'test fixture')]
            class {
                public function __invoke(string $x): array
                {
                    // The bad value arrives typed as `mixed`, which is what a
                    // model-supplied value genuinely is at this point in production.
                    // A `'not-an-int'` literal here would be a violation the analyzer
                    // can prove statically — and `mago analyze` does, as an error —
                    // so it would have to be either suppressed or written this way.
                    // This way is also the more faithful of the two.
                    $this->needsInt(json_decode('"not-an-int"', true));

                    return [];
                }

                private function needsInt(int $n): int
                {
                    return $n;
                }
            };
        $toolbox = new BoundedToolbox(new Toolbox([$tool]), 5, new TraceRecorder());

        $this->expectException(ToolExecutionException::class);

        $toolbox->execute(new ToolCall('call-1', 'body_failure', ['x' => 'hi']));
    }

    /**
     * The empirical edge case the coordinator asked to be recorded, not just fixed: a bad
     * return type (declared `: array`, actually returns a string) lands on the SAME side
     * as an argument-dispatch mismatch, because PHP raises both from the same call
     * boundary — `getTrace()[0]['file']` for a bad return type is also `Toolbox`'s own
     * file, the frame that invoked the method whose contract it violated on the way out.
     * That is the correct answer here, not a gap: unlike an argument shape (which only
     * exists at runtime because the model supplies it), a bad return type is deterministic
     * given the tool's own code, so `composer run quality`'s `mago analyze` step already
     * rejects it statically — this branch could only ever be reached if the quality gate
     * itself had already failed, which makes swallowing it behind a note an acceptable,
     * low-stakes side effect of a check whose real job is the argument-shape/body-bug
     * distinction proven by the two tests above.
     */
    public function testUnwrapsATypeErrorFromABadReturnTypeTheSameWayAsArgumentDispatch(): void
    {
        $trace = new TraceRecorder();
        $tool = new
            #[AsTool(name: 'bad_return', description: 'test fixture')]
            class {
                public function __invoke(string $x): array
                {
                    // Returned as `mixed` for the same reason as the test above: a
                    // `'not-an-array'` literal is an error `mago analyze` proves
                    // statically. That the gate catches the literal is precisely the
                    // argument this test's docblock makes about production code — so
                    // reaching this branch at all requires hiding the violation from
                    // the analyzer, which is what this does.
                    return json_decode('"not-an-array"', true);
                }
            };
        $toolbox = new BoundedToolbox(new Toolbox([$tool]), 5, $trace);

        $result = $toolbox->execute(new ToolCall('call-1', 'bad_return', ['x' => 'hi']));

        $payload = $result->getResult();
        self::assertIsArray($payload);
        self::assertArrayHasKey('note', $payload);
        $note = $payload['note'];
        self::assertIsString($note);
        self::assertStringContainsString('Return value must be of type array', $note);

        $rejectedEvents = array_values(array_filter(
            $trace->events(),
            static fn($event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertCount(1, $rejectedEvents);
        self::assertSame('bad_return', $rejectedEvents[0]->payload['name'] ?? null);
    }

    /**
     * The same boundary from the other side: a `ToolExecutionException` wrapping
     * something that is neither `ToolArgumentException`, a `SerializerExceptionInterface`
     * nor an argument-dispatch `\TypeError` is a genuine server fault and must still
     * kill the turn rather than being swallowed behind a note the model shrugs off. This
     * boundary now carries more weight than before the `\TypeError` handling was added,
     * so it gets its own explicit case rather than relying on the absence of a failure
     * elsewhere.
     */
    public function testRethrowsAToolExecutionExceptionWrappingAGenericFailure(): void
    {
        $toolCall = new ToolCall('call-1', 'search_products', ['options' => 'Blue']);
        $toolbox = $this->toolboxWrapping($toolCall, new \RuntimeException('database is down'), new TraceRecorder());

        $this->expectException(ToolExecutionException::class);

        $toolbox->execute($toolCall);
    }
}
