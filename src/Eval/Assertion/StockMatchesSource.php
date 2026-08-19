<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The direct check for the failure {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver}
 * exists to prevent: reporting a parent product's aggregate stock in answer to a variant
 * question. `expect` maps a rendered card id to the stock figure it must carry; when
 * `scope === 'variant'`, the card must also carry {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Variant}
 * — a card with the right id but `Parent` means the figure leaked from the aggregate
 * even though the id looks correct. That per-card check is
 * {@see VariantStockCheck::evaluate()}, split out to keep this class's own
 * cyclomatic-complexity total under this project's threshold.
 *
 * With `scope === 'variant'` this assertion also requires a `variant.resolve` trace
 * event to exist at all: its total absence means variant resolution was never even
 * attempted for this turn, which is its own distinct failure from a resolution that ran
 * and produced the wrong figure.
 */
final class StockMatchesSource implements Assertion
{
    public function name(): string
    {
        return 'stock_matches_source';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        /** @var array<string, int> $expect */
        $expect = $expectations['expect'] ?? [];
        $scope = $expectations['scope'] ?? null;

        if ('variant' === $scope && null === $trace->payload('variant.resolve')) {
            return new AssertionResult($this->name(), false, 'no variant.resolve event recorded');
        }

        foreach ($expect as $expectedId => $expectedStock) {
            $result = VariantStockCheck::evaluate($this->name(), $turn, $expectedId, (int) $expectedStock, $scope);

            if (!$result->passed) {
                return $result;
            }
        }

        return new AssertionResult($this->name(), true, 'stock matches its source for every expected card');
    }

    public function isSafety(): bool
    {
        return true;
    }
}
