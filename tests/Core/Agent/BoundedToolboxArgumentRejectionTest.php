<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\BoundedToolbox;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
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
     * `ToolExecutionException` the exact same way as the Serializer case — confirmed
     * empirically the same way. This message is exactly what PHP itself produces for
     * this failure (reproduced verbatim against the installed vendor package with a
     * throwaway `?array`-typed tool argument), including the `"called in %s on line %d"`
     * suffix naming {@see Toolbox}'s own file, which is what {@see BoundedToolbox::isArgumentDispatchTypeError()}
     * keys on.
     */
    public function testUnwrapsATypeErrorFromArgumentDispatchWrappedInToolExecutionExceptionIntoARetryableNote(): void
    {
        $trace = new TraceRecorder();
        $toolCall = new ToolCall('call-1', 'search_products', ['options' => 'Blue']);
        $vendorToolboxFile = (new \ReflectionClass(Toolbox::class))->getFileName();
        self::assertIsString($vendorToolboxFile);
        $toolbox = $this->toolboxWrapping(
            $toolCall,
            new \TypeError(\sprintf(
                'SearchProductsTool::__invoke(): Argument #5 ($options) must be of type ?array, string given, '
                . 'called in %s on line 110',
                $vendorToolboxFile,
            )),
            $trace,
        );

        $result = $toolbox->execute($toolCall);

        $payload = $result->getResult();
        self::assertIsArray($payload);
        self::assertArrayHasKey('note', $payload);
        $note = $payload['note'];
        self::assertIsString($note);
        self::assertStringContainsString('Argument #5 ($options) must be of type ?array', $note);

        $rejectedEvents = array_values(array_filter(
            $trace->events(),
            static fn($event): bool => 'tool.arguments.rejected' === $event->stage,
        ));
        self::assertCount(1, $rejectedEvents);
        self::assertSame('search_products', $rejectedEvents[0]->payload['name'] ?? null);
    }

    /**
     * The boundary {@see BoundedToolbox::isArgumentDispatchTypeError()} exists to draw:
     * a `\TypeError` raised three frames deep inside a tool's OWN body (a wrong-typed
     * argument to some collaborator the tool calls, unrelated to Symfony AI's own
     * argument dispatch) must still propagate as a genuine server fault, not become a
     * note the model shrugs off. Its message deliberately names some other file in the
     * `"called in %s on line %d"` clause — never {@see Toolbox}'s own file — which is
     * exactly what a real one looks like (reproduced empirically with a throwaway tool
     * that calls a strictly-typed helper with a bad argument inside its own `__invoke()`).
     */
    public function testRethrowsAToolExecutionExceptionWrappingATypeErrorNotFromArgumentDispatch(): void
    {
        $toolCall = new ToolCall('call-1', 'search_products', ['options' => 'Blue']);
        $toolbox = $this->toolboxWrapping(
            $toolCall,
            new \TypeError(
                'SomeCollaborator::helper(): Argument #1 ($n) must be of type int, string given, '
                . 'called in /app/src/Core/Tool/SearchProductsTool.php on line 42',
            ),
            new TraceRecorder(),
        );

        $this->expectException(ToolExecutionException::class);

        $toolbox->execute($toolCall);
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
