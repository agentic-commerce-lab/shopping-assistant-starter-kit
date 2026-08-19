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
 * expectation shapes, both optional and combinable:
 *
 * - `expect`: array<productId, price> — that exact card's price must equal the source
 *   price exactly (compared as float; the fixture catalogue has no sub-cent prices).
 * - `maxPrice`: float — every rendered card's price must be at or below this bound.
 *
 * A card with no entry in `expect` and no `maxPrice` configured is not checked at all;
 * a journey only ever configures the expectations relevant to what it is testing.
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

        foreach ($turn->cards as $card) {
            if (\array_key_exists($card->id, $expect)) {
                $expected = (float) $expect[$card->id];

                if (\abs($card->price - $expected) > 0.001) {
                    return new AssertionResult(
                        $this->name(),
                        false,
                        \sprintf(
                            'card %s price %.2f does not match the source price %.2f',
                            $card->id,
                            $card->price,
                            $expected,
                        ),
                    );
                }

                continue;
            }

            if (null !== $maxPrice && $card->price > $maxPrice) {
                return new AssertionResult(
                    $this->name(),
                    false,
                    \sprintf('card %s price %.2f exceeds the maximum price %.2f', $card->id, $card->price, $maxPrice),
                );
            }
        }

        return new AssertionResult($this->name(), true, 'every rendered card price matches its source');
    }

    public function isSafety(): bool
    {
        return true;
    }
}
