<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Finds every mention of one attribute value in a reply and decides whether any of them **asserts**
 * it.
 *
 * Split from {@see PropertyNegation} under the complexity the gate allows, and the seam is the right
 * one anyway — the same one that separates {@see AvailabilityClaimExtractor} from
 * {@see AvailabilityNegation}. Reading the prose and knowing which words take a claim back are two
 * jobs, revised for different reasons: this file changes when a model finds a new way to phrase a
 * mention, that one when it finds a new way to deny one.
 *
 * {@see PropertyClaimExtractor} decides *which* values to ask about — the shop's own closed
 * vocabulary — and this answers, for one of them, whether the reply stands behind it.
 */
final class PropertyMention
{
    /**
     * How many characters before a value are read, before clause trimming narrows it further.
     *
     * Wider than {@see AvailabilityClaimExtractor}'s forty, because one negation routinely governs a
     * list: in *"no dresses in red or burgundy in size S"* the `no` sits twenty-one characters from
     * `burgundy`, and a list of three colours pushes the last one further still. The clause boundary
     * rather than this number is what keeps it honest.
     */
    private const WINDOW = 80;

    private function __construct() {}

    /**
     * Whether `$normalisedProse` **asserts** `$value`, rather than only denying it.
     *
     * **One un-negated mention is enough.** *"We have no blue, but this one is blue"* both denies and
     * asserts, and the assertion is the half that needs a card behind it — so every occurrence is
     * examined and the value counts as claimed as soon as one of them stands.
     *
     * The preceding window is captured as part of the match rather than located by byte offset, the
     * choice {@see AvailabilityClaimExtractor::matching()} explains: `PREG_OFFSET_CAPTURE` makes the
     * offset's type unprovable, and a regex carrying its own context beats index arithmetic.
     *
     * **The quantifier is lazy, and that is not a style choice.** A greedy `.{0,N}` starting at the
     * top of the prose reaches forward for the *last* occurrence within its reach and swallows every
     * earlier one into its own window, so they are never examined separately. Measured on
     * *"this one is merino, but we have no merino"*: greedy produced one match whose window was
     * *"this one is merino, but we have no"*, trimmed at `but` to *"we have no"* — reporting no claim
     * and losing the assertion in front of it. Lazy produces two matches with clause-local windows.
     *
     * @param string $normalisedProse already lowercased by the caller
     * @param string $value           already lowercased by the caller
     */
    public static function assertedIn(string $normalisedProse, string $value): bool
    {
        // Group 1: the preceding window. Groups 2 and 3 are LOOKAHEADS so they consume nothing —
        // consumed, they would swallow a later mention of the same value and it would never be
        // examined. Group 2 is the rest of the clause; group 3 runs on to the sentence's own
        // terminator, which is the only way to know whether this sentence is asking or telling.
        $pattern = \sprintf(
            '/(.{0,%d}?)(?<![\p{L}\p{N}])%s(?![\p{L}\p{N}])(?=([^.!?;,\n]{0,200}))(?=([^.!?\n]{0,400}([.!?])?))/su',
            self::WINDOW,
            preg_quote($value, '/'),
        );

        if (preg_match_all($pattern, $normalisedProse, $matches, \PREG_SET_ORDER) === false) {
            return false;
        }

        foreach ($matches as $match) {
            if (self::stands($match[1] ?? '', $match[2] ?? '', $match[4] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one mention actually asserts the value.
     *
     * Three ways it does not, in the order they cost the least to check.
     */
    private static function stands(string $before, string $clauseAfter, string $terminator): bool
    {
        // A question offers, it does not state. "Would you like Blush, Emerald, or Navy?" names shop
        // vocabulary as a next step, which the prompt asks the assistant to do.
        if ($terminator === '?') {
            return false;
        }

        return !PropertyNegation::before($before) && !PropertyNegation::after($clauseAfter);
    }
}
