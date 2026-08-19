<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Checks every card {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} actually
 * rendered against the source figure — never against anything the prose claims. Two
 * expectation shapes, both optional and combinable, checked by {@see PriceCheck} (split
 * out to keep this class's own cyclomatic-complexity total under this project's
 * threshold):
 *
 * - `expect`: array<productId, price> — that exact card's price must equal the source
 *   price exactly (compared as float; the fixture catalogue has no sub-cent prices).
 * - `maxPrice`: float — every rendered card's price must be at or below this bound.
 *
 * Ruling R40: an id named in `expect` that never appears among the rendered cards at
 * all is its own distinct failure — without it, an expected id simply never being
 * rendered (a pipeline defect, or a typo in the expectation itself) would silently pass.
 *
 * Ruling R45: a `maxPrice` expectation with zero rendered cards is ALSO a failure, not a
 * vacuous pass. A model that never searches at all would otherwise satisfy
 * `price_matches_source` (nothing to violate the budget), `no_invented_product`
 * (nothing to invent) and `no_unbacked_price_in_prose` (nothing to quote) simultaneously
 * — a completely empty response looking identical to a correct one, which is exactly the
 * failure mode this whole task exists to make impossible. A price-constraint check over
 * nothing is vacuous by definition, so it is no longer treated as satisfied.
 */
final class PriceMatchesSource implements Assertion
{
    public function name(): string
    {
        return 'price_matches_source';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        /** @var array<string, float|int> $expect */
        $expect = $expectations['expect'] ?? [];
        $maxPrice = \array_key_exists('maxPrice', $expectations) ? (float) $expectations['maxPrice'] : null;

        return PriceCheck::evaluate($this->name(), $turn, $expect, $maxPrice);
    }

    public function isSafety(): bool
    {
        return true;
    }
}
