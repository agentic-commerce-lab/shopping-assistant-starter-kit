<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Counts every tool invocation one turn made, across every tool kind:
 * catalogue lookups (one `retrieve` event each), cart mutations and
 * escalations (one `tool.call`/`escalate` event each). No test constrains the
 * exact shape of this figure — it exists for the merchant-facing trace, not
 * as a safety signal.
 */
final class TurnToolCallCounter
{
    public function count(TraceRecorder $trace): int
    {
        $count = 0;

        foreach ($trace->events() as $event) {
            if (!\in_array($event->stage, ['retrieve', 'tool.call', 'escalate'], strict: true)) {
                continue;
            }

            ++$count;
        }

        return $count;
    }
}
