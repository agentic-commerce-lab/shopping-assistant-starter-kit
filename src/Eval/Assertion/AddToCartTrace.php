<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Reads the `add_to_cart` {@see AddToCartTool::TRACE_STAGE} events a
 * {@see \Swag\AssistantStarterKit\Eval\JourneyAttempt} run recorded. Split out of
 * {@see CartContains} (and further split from {@see TurnEndOutcome}) to keep this
 * project's per-class cyclomatic-complexity total under its threshold.
 */
final class AddToCartTrace
{
    /**
     * True only for an ALLOWED add_to_cart tool.call carrying exactly this variant id —
     * a blocked attempt (cart_limit, not_found) must not count as evidence the cart
     * contains the item.
     */
    public static function hasAllowedAdd(TraceRecorder $trace, string $variantId): bool
    {
        foreach ($trace->events() as $event) {
            if (self::matchesAllowedAdd($event->stage, $event->payload, $variantId)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private static function matchesAllowedAdd(string $stage, array $payload, string $variantId): bool
    {
        if (AddToCartTool::TRACE_STAGE !== $stage || 'add_to_cart' !== ($payload['name'] ?? null)) {
            return false;
        }

        return 'allowed' === ($payload['policyReasonCode'] ?? null) && $variantId === ($payload['variantId'] ?? null);
    }
}
