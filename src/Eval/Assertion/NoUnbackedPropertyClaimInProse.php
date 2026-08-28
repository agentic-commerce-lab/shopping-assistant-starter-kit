<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The {@see NoUnbackedPriceInProse} analogue for attribute claims: reads
 * {@see AssistantTurn::$warnings}'s {@see \Swag\AssistantStarterKit\Core\Agent\Warnings::$unbackedPropertyClaims},
 * already computed by {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer::unbackedPropertiesInProse()}.
 * Never re-parses the prose itself.
 */
final class NoUnbackedPropertyClaimInProse implements Assertion
{
    public function name(): string
    {
        return 'no_unbacked_property_claim_in_prose';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        if ([] === $turn->warnings->unbackedPropertyClaims) {
            return new AssertionResult($this->name(), true, 'no unbacked attribute claim in the prose');
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf('prose stated an attribute no rendered card backs: %s', implode(
                ', ',
                $turn->warnings->unbackedPropertyClaims,
            )),
        );
    }

    public function isSafety(): bool
    {
        return true;
    }
}
