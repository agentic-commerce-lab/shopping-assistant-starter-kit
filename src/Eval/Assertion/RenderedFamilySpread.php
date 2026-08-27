<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The rendered cards span at least this many distinct product families, not just relevance-ranked
 * size/colour variants of the same one or two products.
 *
 * ## The failure it catches
 *
 * Measured live, `docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md`: "show me dresses"
 * (1,355 matches) and "show me yoga clothes" both returned cards that were multiple variants of the
 * same families, not a spread of styles. Every existing assertion passed straight through that — the
 * cards are real products, correctly priced, correctly counted; nothing in the suite noticed the
 * shortlist was one product's size run wearing eight labels.
 *
 * Family identity reuses {@see FamilyDiversifier::familyKey()} — the exact same "what counts as one
 * family" definition the narrowing fix itself uses, so this assertion tests the thing the fix actually
 * changed, not a different notion of variety.
 */
final class RenderedFamilySpread implements Assertion
{
    public function name(): string
    {
        return 'rendered_family_spread';
    }

    /** @param array<string, mixed> $expectations */
    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        $min = $expectations['min'] ?? null;

        if (!\is_int($min) || $min < 2) {
            return new AssertionResult(
                $this->name(),
                false,
                'Declare an integer "min" of 2 or more. A floor below two asserts nothing about spread.',
            );
        }

        $families = [];
        foreach ($turn->cards as $card) {
            $families[FamilyDiversifier::familyKey($card)] = true;
        }

        $spread = \count($families);

        if ($spread >= $min) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf(
                    '%d distinct families among %d rendered card(s), at least %d required.',
                    $spread,
                    \count($turn->cards),
                    $min,
                ),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'only %d distinct families among %d rendered card(s) — floor of %d not met; the shortlist may be size/colour repeats of the same product(s).',
                $spread,
                \count($turn->cards),
                $min,
            ),
        );
    }

    /**
     * Not safety: a low-diversity shortlist is a worse answer, not an unsafe one — same posture as
     * {@see RenderedIdsFromEach::isSafety()} for the same reason (model variance is real).
     */
    public function isSafety(): bool
    {
        return false;
    }
}
