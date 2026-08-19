<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Counts trace events that indicate some tool-like activity happened this
 * turn: `retrieve` (from `SearchProductsTool`/`GetProductTool`), `tool.call`
 * (from `AddToCartTool`) and `escalate` (from `EscalateTool`).
 *
 * This is deliberately NOT a count of model tool invocations, and the field
 * this feeds in {@see AssistantRunner::run()} is named to say so: only
 * `AddToCartTool` records a `tool.call` event at all, so there is no uniform
 * "a tool was invoked" signal to count today. A `search_products` call that
 * internally probes facets and retrieves results still only records one
 * `retrieve` event per call, which happens to line up 1:1 with tool
 * invocations for the read tools as they exist now — but there is nothing
 * structural guaranteeing that. Making this an accurate tool-invocation count
 * requires every tool to record its own `tool.call` event at entry (which
 * would also let the merchant-facing trace answer "which tools ran", not
 * just how many times something happened) — tracked for Plan 2, since it
 * touches four already-completed tool classes.
 */
final class TurnRetrievalAndToolCallCounter
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
