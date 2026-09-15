<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CappedMatchCountReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\MatchCountReader;

/**
 * How many products a multi-term search really matched, when the gateway can count.
 *
 * Extracted from {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool} because that file sits
 * against this project's 400-line cap. The boundary is reasonable on its own: this is arithmetic over
 * the passes, and the tool is about the turn.
 *
 * **The largest single term's count, not a sum.** Counts alone cannot be de-duplicated and two terms
 * usually overlap, so summing would double every product both matched. Overstating the catalogue is the
 * one direction this number must never err in, because its whole purpose is telling "all six occasion
 * dresses" from "eight of eleven hundred". With one term — the ordinary case — it is simply exact.
 *
 * **No count at all when a stated budget was enforced after the query.** The gateway counts with a SQL
 * aggregation over the same criteria the search used, so a `price` range in those criteria is counted
 * on `product.price` — the list price. When {@see StatedBudget} has dropped cards, that range provably
 * disagrees with this shopper's own prices, and the count is inflated by exactly the products the
 * shopper cannot afford.
 *
 * Measured 2026-09-01, as a B2B customer with a +50% surcharge asking for jerseys under 100 euros: 29
 * of 32 candidates were over budget once their real prices were known, and the aggregation still said
 * 33 — which the model dutifully reported as *"quite a few more matching that price (33 in total)"*.
 * That is the overstatement the paragraph above says must never happen, so the count is withheld
 * instead. `matched` then falls back to the floor-with-`more` shape it had before exact counting
 * existed, which is honest about being a floor.
 */
final class ExactMatchCount
{
    private function __construct() {}

    /**
     * Where the exact number stops being worth what it costs.
     *
     * **100, and the reasoning is about the reader rather than the database.** A search returns at
     * most 8 cards, so 100 is already twelve screens of results; a shopper told *"there are 4.812
     * more"* and one told *"there are very many"* will do the same thing next, which is narrow the
     * search. Below it the figure is genuinely useful — *"there are 3 more"* changes what someone
     * does.
     *
     * It also happens to bound the cost. Measured on a 118,232-product shop, 2026-09-15: counting
     * every match of `kette` took 1.640 ms, and stopping at a cap took 100–500 ms — with the
     * difference that the capped cost follows the CAP and not the catalogue, which is what makes it
     * survive a shop twenty times this size.
     */
    public const CAP = 100;

    /**
     * @param list<IntentCandidates> $candidates
     */
    public static function of(object $gateway, array $candidates, CatalogScope $scope): ?MatchCount
    {
        if (!$gateway instanceof MatchCountReader) {
            return null;
        }

        foreach ($candidates as $one) {
            if ($one->budgetNarrowed) {
                return null;
            }
        }

        $largest = null;

        foreach ($candidates as $one) {
            $count = self::countOne($gateway, $one, $scope);

            // **One candidate at the cap settles it.** The reply is the LARGEST of the counts, so
            // once any of them says "very many" the others cannot change the answer — and each one
            // skipped is a whole extra count query not run.
            if ($count->capped) {
                return $count;
            }

            $largest = $largest === null ? $count : ($count->count > $largest->count ? $count : $largest);
        }

        return $largest;
    }

    private static function countOne(
        MatchCountReader $gateway,
        IntentCandidates $candidate,
        CatalogScope $scope,
    ): MatchCount {
        $query = $candidate->buildResult->query;

        if (!$gateway instanceof CappedMatchCountReader) {
            return MatchCount::exact($gateway->countMatches($query, $scope));
        }

        $count = $gateway->countMatchesUpTo($query, $scope, self::CAP);

        return $count >= self::CAP ? MatchCount::atLeast(self::CAP) : MatchCount::exact($count);
    }
}
