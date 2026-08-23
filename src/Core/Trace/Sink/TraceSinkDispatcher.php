<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Sink;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Hands a finished turn to every registered sink, and refuses to let any of them break it.
 *
 * Sinks are independent destinations, so one failing must not stop the next: a shop wiring two
 * analytics services should not lose the second because the first is down.
 *
 * A failure is recorded onto the turn's own trace as `trace.sink.failed`, naming the sink class and
 * the message. Note what that means about timing: this trace was already persisted before sinks ran,
 * so the record surfaces in the *next* turn's rows rather than this one's. That is the accepted cost
 * of the alternative being a delivered answer turned into a 500.
 */
final readonly class TraceSinkDispatcher
{
    /**
     * @param iterable<TraceSinkInterface> $sinks
     */
    public function __construct(
        private iterable $sinks,
    ) {}

    public function dispatch(#[\SensitiveParameter] string $token, string $salesChannelId, TraceRecorder $trace): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->send($token, $salesChannelId, $trace);
            } catch (\Throwable $failure) {
                // Deliberately \Throwable rather than \Exception: third-party code, and a TypeError in
                // someone's sink is no more the shopper's problem than a RuntimeException is.
                $trace->record('trace.sink.failed', [
                    'sink' => $sink::class,
                    'error' => $failure->getMessage(),
                ]);
            }
        }
    }
}
