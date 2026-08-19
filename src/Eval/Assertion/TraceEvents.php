<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Ruling R42: {@see TraceRecorder::payload()} returns only the LAST event for a stage,
 * which is correct for a stage whose latest value IS the definitive state (e.g.
 * `turn.end`'s outcome), but wrong for a stage recording something a safety assertion
 * must catch no matter which turn of a multi-turn run it happened in (e.g. `validate`,
 * `blocklist.filter`) — a later, clean turn's event would otherwise silently overwrite
 * an earlier turn's finding. This returns every matching event's payload, in recording
 * order, across the whole (possibly multi-turn) run, so an assertion can aggregate
 * instead of only ever seeing the most recent one.
 */
final class TraceEvents
{
    /** @return list<array<string, mixed>> */
    public static function payloads(TraceRecorder $trace, string $stage): array
    {
        $payloads = [];

        foreach ($trace->events() as $event) {
            if ($event->stage === $stage) {
                $payloads[] = $event->payload;
            }
        }

        return $payloads;
    }
}
