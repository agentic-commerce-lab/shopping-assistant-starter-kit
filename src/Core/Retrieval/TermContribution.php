<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Which terms of a multi-term search put nothing in front of the shopper.
 *
 * ## Why the tool has to say
 *
 * Measured 2026-08-27 on a 15,218-unit fashion catalogue: `terms: ["Occasion Dresses",
 * "Occasion Suits"]` returned eight cards, all of them dresses, at every limit tried. The cause was
 * retrieval — `FixtureTermMatcher`'s all-tokens pass fails for "Occasion Suits" and its any-token
 * fallback then matches "occasion" alone, returning the dress term's list a second time — so
 * interleaving had no difference to preserve.
 *
 * Left undisclosed, the model writes *"here are dresses and suits"* while only dresses render. That is
 * the exact prose/cards mismatch multi-term search was built to remove, arriving through a different
 * door: the shopper reads about something the cards beside it do not contain.
 *
 * So this is a disclosure, in the same idiom as {@see \Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies}
 * and {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool::NO_MATCH_NOTE}: the reply states
 * what it does NOT contain, rather than letting silence read as coverage. It fixes nothing about
 * retrieval, and is not meant to — a term that matched the wrong products is a retrieval problem, and
 * pretending otherwise here would hide it.
 *
 * ## What it deliberately stays quiet about
 *
 * - **A single-term search.** It cannot be one-sided, so the disclosure would be noise on every
 *   ordinary search the assistant makes.
 * - **A search where every term failed.** `NO_MATCH_NOTE` already tells the model the whole search came
 *   up empty, and two notes about one fact is how a model starts ignoring both.
 */
final class TermContribution
{
    private function __construct() {}

    /**
     * The terms that put nothing DISTINCT in front of the shopper.
     *
     * Attribution, not presence: each shown card is credited to the FIRST term whose candidates
     * contained it, mirroring {@see CandidateInterleave}'s first-occurrence dedup. A term whose whole
     * list duplicates an earlier term's has contributed nothing the shopper can see, even though every
     * card it found is on screen — which is exactly the measured case, where "Occasion Suits" returned
     * the dress term's list a second time.
     *
     * @param array<string, list<ProductCard>> $candidatesByTerm each term's candidate window, keyed by
     *                                                           the term the model actually sent
     * @param list<ProductCard>                $returned         the narrowed set the shopper will see
     *
     * @return list<string>
     */
    public static function termsWithoutResults(array $candidatesByTerm, array $returned): array
    {
        if (\count($candidatesByTerm) < 2 || $returned === []) {
            return [];
        }

        $unclaimed = [];

        foreach ($returned as $card) {
            $unclaimed[$card->id] = true;
        }

        $missing = [];

        foreach ($candidatesByTerm as $term => $candidates) {
            if (!self::claim($candidates, $unclaimed)) {
                $missing[] = (string) $term;
            }
        }

        return $missing;
    }

    /**
     * Credits every still-unclaimed shown card this term found, and says whether it claimed any.
     *
     * @param list<ProductCard>   $candidates
     * @param array<string, true> $unclaimed  shown cards no earlier term has claimed, by reference
     *
     * @param-out array<string, true> $unclaimed
     */
    private static function claim(array $candidates, array &$unclaimed): bool
    {
        $claimed = false;

        foreach ($candidates as $candidate) {
            if (isset($unclaimed[$candidate->id])) {
                unset($unclaimed[$candidate->id]);
                $claimed = true;
            }
        }

        return $claimed;
    }
}
