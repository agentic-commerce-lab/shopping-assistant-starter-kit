<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Reads the machine-readable outcome for one turn straight out of the trace,
 * per the task brief's priority order: an `escalate` event beats a
 * successful cart add, which beats cards having been shown, which beats the
 * fallback of nothing having happened at all.
 *
 * Split out of {@see AssistantRunner} to keep that class's own job — driving
 * the guard, the agent call and the message bag — from growing past a single
 * responsibility; mago's cyclomatic-complexity check flagged the combined
 * class. Tool-call counting is a separate, unrelated concern, split further
 * into {@see TurnRetrievalAndToolCallCounter} for the same reason.
 */
final class TurnOutcomeResolver
{
    /**
     * @param list<ProductCard> $cards
     */
    public function outcome(TraceRecorder $trace, array $cards): string
    {
        if (\in_array('escalate', $trace->stages(), strict: true)) {
            return 'escalated';
        }

        if ($this->hasAllowedCartAdd($trace)) {
            return 'cart_added';
        }

        if ($cards !== []) {
            return 'product_shown';
        }

        return 'no_result';
    }

    private function hasAllowedCartAdd(TraceRecorder $trace): bool
    {
        foreach ($trace->events() as $event) {
            if ('tool.call' !== $event->stage) {
                continue;
            }

            if (
                'add_to_cart' === ($event->payload['name'] ?? null)
                && 'allowed' === ($event->payload['policyReasonCode'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }
}
