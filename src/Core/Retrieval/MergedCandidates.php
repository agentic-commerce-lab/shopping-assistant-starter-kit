<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Reads a multi-term search's per-term results as one: which terms produced what, whether any window
 * filled up, and which retry note to pass on.
 *
 * Extracted from {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} when the term-contribution
 * disclosure took that file past this project's 400-line cap. The boundary is the one the cap pointed
 * at anyway: everything here is about reading ACROSS the passes, while the tool is about the turn.
 */
final class MergedCandidates
{
    private function __construct() {}

    /**
     * Each pass's candidate window, keyed by the term the model actually sent.
     *
     * Keyed by term rather than by index because the disclosure names the term back to the model, and
     * an index would make it guess which of its own words came up empty. A term-less pass — a
     * price-only search — is dropped: it has no name to report and cannot be one-sided.
     *
     * @param list<IntentCandidates> $candidates
     *
     * @return array<string, list<ProductCard>>
     */
    public static function byTerm(array $candidates): array
    {
        $byTerm = [];

        foreach ($candidates as $one) {
            $term = $one->buildResult->query->term;

            if ($term !== null && $term !== '') {
                $byTerm[$term] = $one->cards;
            }
        }

        return $byTerm;
    }

    /**
     * The first option/term retry note any pass produced.
     *
     * First rather than concatenated: the notes are instructions to the model about how to read a
     * result, and two of them in one reply is how a model starts ignoring both.
     *
     * @param list<IntentCandidates> $candidates
     */
    public static function firstNote(array $candidates): ?string
    {
        foreach ($candidates as $one) {
            if (null !== $one->note) {
                return $one->note;
            }
        }

        return null;
    }

    /**
     * Whether ANY pass filled its window, which is what makes `matched` a floor rather than a census
     * (T4). Any, not all: one saturated term is already enough for the count to be incomplete.
     *
     * @param list<IntentCandidates> $candidates
     */
    public static function anySaturated(array $candidates): bool
    {
        foreach ($candidates as $one) {
            if ($one->windowSaturated) {
                return true;
            }
        }

        return false;
    }
}
