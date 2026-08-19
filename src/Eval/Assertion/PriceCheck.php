<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The actual per-card and completeness checks for `price_matches_source` — split out of
 * {@see PriceMatchesSource} to keep that class's own cyclomatic-complexity total under
 * this project's threshold (mago sums it per class, across every method); adding the
 * Ruling R45 "maxPrice over zero cards" check pushed the combined method over budget.
 */
final class PriceCheck
{
    /** @param array<string, float|int> $expect */
    public static function evaluate(
        string $assertionName,
        AssistantTurn $turn,
        array $expect,
        ?float $maxPrice,
    ): AssertionResult {
        $renderedIds = [];

        foreach ($turn->cards as $card) {
            $renderedIds[] = $card->id;

            $result = self::checkCard($assertionName, $card, $expect, $maxPrice);
            if (!$result->passed) {
                return $result;
            }
        }

        // Ruling R45: a maxPrice expectation over zero rendered cards is vacuous by
        // definition and must fail, not pass — see PriceMatchesSource's class docblock.
        if (null !== $maxPrice && [] === $renderedIds) {
            return new AssertionResult(
                $assertionName,
                false,
                \sprintf('maxPrice %.2f was configured but no product was rendered to check against it', $maxPrice),
            );
        }

        // Ruling R40: an expected id that never appeared among the rendered cards at all
        // must fail, not vacuously pass by never entering the loop above.
        foreach (array_keys($expect) as $expectedId) {
            if (!\in_array($expectedId, $renderedIds, strict: true)) {
                return new AssertionResult(
                    $assertionName,
                    false,
                    \sprintf(
                        'expected card %s not found among rendered cards [%s]',
                        $expectedId,
                        implode(', ', $renderedIds),
                    ),
                );
            }
        }

        return new AssertionResult($assertionName, true, 'every rendered card price matches its source');
    }

    /** @param array<string, float|int> $expect */
    private static function checkCard(
        string $assertionName,
        ProductCard $card,
        array $expect,
        ?float $maxPrice,
    ): AssertionResult {
        if (\array_key_exists($card->id, $expect)) {
            $expected = (float) $expect[$card->id];

            if (\abs($card->price - $expected) > 0.001) {
                return new AssertionResult(
                    $assertionName,
                    false,
                    \sprintf(
                        'card %s price %.2f does not match the source price %.2f',
                        $card->id,
                        $card->price,
                        $expected,
                    ),
                );
            }

            return new AssertionResult($assertionName, true, 'matches expect');
        }

        if (null !== $maxPrice && $card->price > $maxPrice) {
            return new AssertionResult(
                $assertionName,
                false,
                \sprintf('card %s price %.2f exceeds the maximum price %.2f', $card->id, $card->price, $maxPrice),
            );
        }

        return new AssertionResult($assertionName, true, 'at or below maxPrice');
    }
}
