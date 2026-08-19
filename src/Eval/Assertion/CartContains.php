<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Not in the brief's `src/Eval/Assertion/{...}.php` file list, but required by the
 * `cart_add` journey the brief itself specifies (its `'cart_contains'` assertion) and
 * documented in the brief's own assertion table — see task-13-report.md for the flagged
 * deviation. Checks {@see \Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver}'s
 * `cart_added` outcome and confirms the expected variant id is the one an ALLOWED
 * `add_to_cart` `tool.call` actually recorded — not merely that some cart add happened.
 * The two trace reads live in {@see TurnEndOutcome} and {@see AddToCartTrace}, split
 * out to keep this class's own cyclomatic-complexity total under this project's
 * threshold (mago sums it per class, across every method).
 *
 * Requires the `turn.end` stage unconditionally (Ruling R40): {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner::run()}
 * records it once at the end of every non-guard-blocked turn, so its absence means the
 * turn never completed normally — a distinct failure from "it completed with the wrong
 * outcome", reported separately via {@see RequiredTraceStage}. `tool.call`'s absence is
 * deliberately NOT treated the same way: unlike `turn.end`, it only fires if the model
 * chooses to call `add_to_cart`, and the only journey using this assertion (`cart_add`)
 * exists specifically to test whether that choice happens — its absence is the exact
 * failure this assertion exists to catch, already reported below as its own distinct
 * "no allowed add_to_cart tool.call recorded" message rather than a vacuous pass.
 */
final class CartContains implements Assertion
{
    public function name(): string
    {
        return 'cart_contains';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $expectedVariantId = \is_string($expectations['variantId'] ?? null) ? $expectations['variantId'] : '';

        if (null === $trace->payload('turn.end')) {
            return RequiredTraceStage::missing($this->name(), 'turn.end');
        }

        $outcome = TurnEndOutcome::of($trace);

        if ('cart_added' !== $outcome) {
            return new AssertionResult(
                $this->name(),
                false,
                \sprintf('turn outcome was "%s", expected "cart_added"', $outcome ?? 'null'),
            );
        }

        if (AddToCartTrace::hasAllowedAdd($trace, $expectedVariantId)) {
            return new AssertionResult($this->name(), true, \sprintf('cart contains variant %s', $expectedVariantId));
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('no allowed add_to_cart tool.call recorded for variant %s', $expectedVariantId),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }
}
