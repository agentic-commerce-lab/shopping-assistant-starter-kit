<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Swag\AssistantStarterKit\Core\Insights\Metric\CartFunnel;
use Swag\AssistantStarterKit\Core\Insights\Metric\ClaimAudit;
use Swag\AssistantStarterKit\Core\Insights\Metric\DescriptionCoverage;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchOutcomes;
use Swag\AssistantStarterKit\Core\Insights\Metric\TurnHealth;

/**
 * The four metrics of one run, and the two shapes they are stored in.
 *
 * **`counts()` and `searchTerms()` are separate because the database columns are** (D23). The counts
 * name nobody and are never pruned; the terms are what shoppers typed and are emptied with their
 * conversations. Anything that merged them here would make the prune impossible downstream and the
 * trend chart would have to die with the conversations it was drawn from.
 *
 * Every value in `counts()` is an integer, because the column is charted: a null or a float in that
 * JSON becomes a gap or a wobble in a line a merchant is reading as a trend.
 *
 * **`turnsFoundNothing` and `turnsOverCap` were `searchesEmpty` and `searchesOverCap`**, and the
 * rename is the point rather than tidying: they count turns now, not searches, because the
 * assistant retries a failed search in another language and counting the retries turned three
 * shoppers into five. Renamed rather than redefined in place so that no chart ever plots two
 * meanings on one line — the feature had not shipped, so there is no merchant data to break.
 */
final readonly class InsightMetrics
{
    public function __construct(
        public SearchOutcomes $searches,
        public DescriptionCoverage $descriptions,
        public ClaimAudit $claims,
        public TurnHealth $turns,
        public CartFunnel $cart,
    ) {}

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'conversations' => $this->cart->conversations,
            'cartAdded' => $this->cart->cartAdded,
            'checkoutOffered' => $this->cart->checkoutOffered,
            'searchTurns' => $this->searches->searchTurns,
            'turnsFoundNothing' => $this->searches->turnsFoundNothing,
            'turnsOverCap' => $this->searches->turnsOverCap,
            'turnsWithDescription' => $this->descriptions->turnsWithDescription,
            'turnsWithoutDescription' => $this->descriptions->turnsWithoutDescription,
            'unsupportedClaims' => $this->claims->unsupportedClaims,
            'abortedTurns' => $this->turns->abortedTurns,
            'escalations' => $this->turns->escalations,
            'escalationsWithoutDestination' => $this->turns->escalationsWithoutDestination,
        ];
    }

    /** @return array<string, list<string>> */
    public function searchTerms(): array
    {
        return [
            'empty' => $this->searches->emptyTerms,
            'overCap' => $this->searches->overCapTerms,
        ];
    }
}
