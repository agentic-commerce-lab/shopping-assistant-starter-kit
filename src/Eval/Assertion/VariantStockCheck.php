<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Checks one expected `{id => stock}` pair from a `stock_matches_source` journey
 * assertion against a turn's rendered cards. Split out of {@see StockMatchesSource} to
 * keep that class's own cyclomatic-complexity total under this project's threshold
 * (mago sums it per class, across every method).
 */
final class VariantStockCheck
{
    public static function evaluate(
        string $assertionName,
        AssistantTurn $turn,
        string $expectedId,
        int $expectedStock,
        mixed $scope,
    ): AssertionResult {
        foreach ($turn->cards as $card) {
            if ($card->id === $expectedId) {
                return self::checkFoundCard($assertionName, $card, $expectedId, $expectedStock, $scope);
            }
        }

        return new AssertionResult(
            $assertionName,
            false,
            \sprintf(
                'expected card %s not found among rendered cards [%s]',
                $expectedId,
                implode(', ', array_map(static fn(ProductCard $c): string => $c->id, $turn->cards)),
            ),
        );
    }

    private static function checkFoundCard(
        string $assertionName,
        ProductCard $card,
        string $expectedId,
        int $expectedStock,
        mixed $scope,
    ): AssertionResult {
        // Checked before the plain stock comparison below: a card whose figure did not come from
        // the variant itself is the specific failure this assertion exists to catch, and deserves
        // that diagnosis even when the number happens to coincide with the expected figure.
        //
        // The actual `stockSource` is named rather than assumed to be the aggregate, because there
        // are two ways to fail this and they are different mistakes: `parent` is the family
        // aggregate leaking through, and `product` is a variant question answered with a product
        // that has no variants at all.
        if ('variant' === $scope && StockSource::Variant !== $card->stockSource) {
            return new AssertionResult(
                $assertionName,
                false,
                \sprintf(
                    'card %s stock %d has stockSource=%s, not the variant\'s own; '
                    . 'expected %s stock %d with stockSource=variant',
                    $card->id,
                    $card->stock,
                    $card->stockSource->value,
                    $expectedId,
                    $expectedStock,
                ),
            );
        }

        if ($card->stock !== $expectedStock) {
            return new AssertionResult(
                $assertionName,
                false,
                \sprintf('card %s stock %d does not match expected %d', $card->id, $card->stock, $expectedStock),
            );
        }

        return new AssertionResult(
            $assertionName,
            true,
            \sprintf('card %s stock matches expected %d', $card->id, $expectedStock),
        );
    }
}
