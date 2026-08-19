<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Ruling R40: a stage an assertion depends on being absent from the trace is not "no
 * findings" — it means the pipeline step this assertion reads never ran this turn, which
 * is itself a failure, not a reason to fall back to a vacuous pass. Before this ruling,
 * {@see NoInventedProduct} and {@see BlocklistRespected} both treated a missing stage
 * (whatever the cause — a genuinely idle pipeline, or a typo in the stage-name string
 * literal inside the assertion's own source) as "nothing to complain about", which made a
 * source-level typo in either class indistinguishable from a passing run.
 *
 * This produces one canonical message shape for that failure mode across every
 * assertion, so a reader immediately recognises "the trace did not contain what I
 * needed" and does not mistake it for "the assertion's condition was violated" — a
 * genuinely different failure the assertion's own comparison logic reports separately.
 *
 * Deliberately NOT part of the {@see \Swag\AssistantStarterKit\Eval\Assertion} interface:
 * the interface's exact three methods are dictated verbatim by the task brief, and
 * whether a given assertion requires a stage at all — and which one — is a decision each
 * `evaluate()` makes for itself, so a fixed per-class stage list would not fit every case
 * anyway. Each assertion's own class docblock states, in prose, which stage(s) it
 * requires and under what condition — this class only supplies the shared failure shape
 * once that condition is checked. Current callers: {@see CartContains} (`turn.end`),
 * {@see NoInventedProduct} (`validate`) and {@see BlocklistRespected} (`blocklist.filter`)
 * — each required unconditionally within its own journey.
 *
 * {@see StockMatchesSource} deliberately does NOT use this class, and is not a current
 * caller despite once being one: requiring a `variant.resolve` event whenever
 * `scope === 'variant'` failed turns where a plain search already returned the correct
 * variant card with no resolution step ever needed — see that class's own docblock for
 * why the per-card `stockSource` check it already performs is a strict superset of what
 * the stage requirement caught.
 */
final class RequiredTraceStage
{
    public static function missing(string $assertionName, string $stage): AssertionResult
    {
        return new AssertionResult(
            $assertionName,
            false,
            \sprintf(
                'required stage "%s" is missing from the trace — the pipeline step this assertion depends on did not run this turn',
                $stage,
            ),
        );
    }
}
