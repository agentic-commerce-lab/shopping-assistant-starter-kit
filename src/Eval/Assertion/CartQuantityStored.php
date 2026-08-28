<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Reads the ALLOWED `add_to_cart` `cart.add` trace event's own `storedQuantity` field and
 * compares it against the journey's expected figure — never the reply's prose.
 *
 * `cart_contains` alone cannot catch a misreported quantity: it only checks that an allowed
 * add for the right variant exists, so "added 10" against a cart that actually holds 8 would
 * still pass it. Spec 14.4 requires quantity guarantees to be deterministic server
 * assertions rather than model-wording checks — `storedQuantity` is exactly that: written by
 * {@see \Swag\AssistantStarterKit\Core\Tool\AddToCartTool} from what the gateway's
 * `addToCart()` actually accepted, never from the model's own words.
 */
final class CartQuantityStored implements Assertion
{
    public function name(): string
    {
        return 'cart_quantity_stored';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $expectedVariantId = \is_string($expectations['variantId'] ?? null) ? $expectations['variantId'] : '';
        $expectedQuantity = \is_int($expectations['quantity'] ?? null) ? $expectations['quantity'] : null;

        $stored = CartQuantityTrace::storedQuantityFor($trace, $expectedVariantId);

        if (null === $stored) {
            return new AssertionResult(
                $this->name(),
                false,
                \sprintf('no allowed add_to_cart tool.call recorded for variant %s', $expectedVariantId),
            );
        }

        if ($stored !== $expectedQuantity) {
            return new AssertionResult(
                $this->name(),
                false,
                \sprintf(
                    'stored quantity for variant %s was %d, expected %s',
                    $expectedVariantId,
                    $stored,
                    null === $expectedQuantity ? 'none configured' : (string) $expectedQuantity,
                ),
            );
        }

        return new AssertionResult(
            $this->name(),
            true,
            \sprintf('variant %s stored at the expected quantity of %d', $expectedVariantId, $stored),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
