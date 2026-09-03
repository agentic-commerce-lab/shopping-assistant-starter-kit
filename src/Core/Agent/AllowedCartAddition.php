<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Whether a run put something in the shopper's cart that the policy actually allowed.
 *
 * Extracted from {@see TurnOutcomeResolver} for the reason {@see ShopInfoAnswer} and
 * {@see CheckoutOffer} live outside it: that class is a chain of decisions, each of these is a
 * question about the trace, and Mago bounds complexity per class. It was the last scan still inline
 * and the one that kept the class over its limit once checkout joined the chain.
 *
 * `policyReasonCode` is read, not merely the stage's presence: `add_to_cart` records a refusal under
 * the same stage as an add, and a turn the guardrails stopped did not change the shop's state.
 */
final readonly class AllowedCartAddition
{
    public static function isIn(TraceRecorder $trace): bool
    {
        foreach ($trace->events() as $event) {
            if ($event->stage !== AddToCartTool::TRACE_STAGE) {
                continue;
            }

            if (
                ($event->payload['name'] ?? null) === 'add_to_cart'
                && ($event->payload['policyReasonCode'] ?? null) === 'allowed'
            ) {
                return true;
            }
        }

        return false;
    }
}
