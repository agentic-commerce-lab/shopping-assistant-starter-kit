<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

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
     * @param list<IntentCandidates> $candidates
     */
    public static function of(object $gateway, array $candidates, CatalogScope $scope): ?int
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
            $count = $gateway->countMatches($one->buildResult->query, $scope);
            $largest = $largest === null ? $count : max($largest, $count);
        }

        return $largest;
    }
}
