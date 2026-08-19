<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Reads the `turn.end` stage's `outcome` field. Split into its own class, alongside
 * {@see AddToCartTrace}, to keep both under this project's per-class
 * cyclomatic-complexity threshold (mago sums it per class, across every method).
 */
final class TurnEndOutcome
{
    public static function of(TraceRecorder $trace): ?string
    {
        $turnEnd = $trace->payload('turn.end');
        $outcome = \is_array($turnEnd) ? $turnEnd['outcome'] ?? null : null;

        return \is_string($outcome) ? $outcome : null;
    }
}
