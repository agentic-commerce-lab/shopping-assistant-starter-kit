<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Reads the `storedQuantity` an ALLOWED `add_to_cart` {@see AddToCartTool::TRACE_STAGE}
 * event recorded for one variant — split into its own class, alongside
 * {@see AddToCartTrace} and {@see TurnEndOutcome}, rather than added as a second method on
 * {@see AddToCartTrace}: mago sums cyclomatic complexity per class across every method,
 * and {@see AddToCartTrace} was already at its threshold. The one small duplication this
 * causes — the same allowed-add predicate {@see AddToCartTrace::hasAllowedAdd()} checks —
 * is the deliberate trade the project has made at this exact boundary before.
 */
final class CartQuantityTrace
{
    /**
     * The stored quantity, or null when no ALLOWED add_to_cart event exists for this
     * variant — a blocked attempt or a missing `storedQuantity` key both read as "no
     * evidence", never as a quantity of zero.
     */
    public static function storedQuantityFor(TraceRecorder $trace, string $variantId): ?int
    {
        foreach ($trace->events() as $event) {
            if (!self::matchesAllowedAdd($event->stage, $event->payload, $variantId)) {
                continue;
            }

            $stored = $event->payload['storedQuantity'] ?? null;

            return \is_int($stored) ? $stored : null;
        }

        return null;
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
