<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Sink;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\Sink\TraceSinkDispatcher;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Sinks are third-party code running inside a shopper's request, after the turn already succeeded and
 * its trace is already persisted. Everything here is about that ordering.
 */
final class TraceSinkDispatcherTest extends TestCase
{
    public function testEachSinkReceivesTheTurnsTrace(): void
    {
        $sink = new RecordingTraceSink();
        $trace = new TraceRecorder();
        $trace->record('turn.end', ['outcome' => 'product_shown']);

        (new TraceSinkDispatcher([$sink]))->dispatch('tok', 'chan', $trace);

        self::assertSame(1, $sink->calls);
        self::assertSame('tok', $sink->lastToken);
        self::assertSame('chan', $sink->lastSalesChannelId);
        self::assertSame($trace, $sink->lastTrace);
    }

    public function testAThrowingSinkCannotBreakADeliveredTurn(): void
    {
        // The answer is already on its way to the shopper and the audit row is already written. A
        // broken analytics integration turning a delivered answer into a 500 would be the worst trade
        // available, so the dispatcher swallows and records instead.
        $trace = new TraceRecorder();

        (new TraceSinkDispatcher([new ExplodingTraceSink()]))->dispatch('tok', 'chan', $trace);

        self::assertNotNull($trace->payload('trace.sink.failed'));
    }

    public function testOneFailingSinkDoesNotStopTheOthers(): void
    {
        // Independent destinations. A shop wiring two analytics services should not lose the second
        // because the first is down.
        $healthy = new RecordingTraceSink();

        (new TraceSinkDispatcher([new ExplodingTraceSink(), $healthy]))->dispatch('tok', 'chan', new TraceRecorder());

        self::assertSame(1, $healthy->calls);
    }

    public function testTheFailureRecordNamesTheSinkThatFailed(): void
    {
        // "a sink failed" is not actionable in a shop with three of them.
        $trace = new TraceRecorder();

        (new TraceSinkDispatcher([new ExplodingTraceSink()]))->dispatch('tok', 'chan', $trace);

        $payload = $trace->payload('trace.sink.failed');
        self::assertIsArray($payload);
        self::assertSame(ExplodingTraceSink::class, $payload['sink'] ?? null);
        self::assertStringContainsString('sink is down', (string) ($payload['error'] ?? ''));
    }

    public function testNoSinksMeansNoWork(): void
    {
        $trace = new TraceRecorder();

        (new TraceSinkDispatcher([]))->dispatch('tok', 'chan', $trace);

        self::assertSame([], $trace->events());
    }
}
