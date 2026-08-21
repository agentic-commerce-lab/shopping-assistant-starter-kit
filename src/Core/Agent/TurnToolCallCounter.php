<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Counts how many tools the model invoked this turn.
 *
 * **Corrected, 2026-08-21.** This used to count `retrieve` events *and* `tool.call` events *and*
 * `escalate`, and its docblock justified the mixture: *"only `AddToCartTool` records a `tool.call`
 * event at all, so there is no uniform 'a tool was invoked' signal to count today."* That was
 * already untrue — {@see BoundedToolbox::execute()} records `tool.call` on every dispatch — so the
 * sum double-counted every read tool once for its dispatch and again for its retrieval. Measured on
 * a live turn with three tool calls: it reported five.
 *
 * `tool.call` is the uniform signal the old docblock said did not exist: one event per dispatch,
 * recorded in one place, for every tool. Nothing else is needed, and adding anything else to the sum
 * is what made the number meaningless.
 *
 * Retrieval stages are still worth reading — they are simply a different question, answered by the
 * `retrieve` rows themselves, which say what was searched for and what came back rather than only
 * how many times something happened.
 */
final class TurnToolCallCounter
{
    public function count(TraceRecorder $trace): int
    {
        $count = 0;

        foreach ($trace->events() as $event) {
            if ('tool.call' !== $event->stage) {
                continue;
            }

            ++$count;
        }

        return $count;
    }
}
