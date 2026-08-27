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

        $largest = null;

        foreach ($candidates as $one) {
            $count = $gateway->countMatches($one->buildResult->query, $scope);
            $largest = $largest === null ? $count : max($largest, $count);
        }

        return $largest;
    }
}
