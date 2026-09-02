<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

/**
 * How many units retrieval reads before anything narrows them to what the model asked for.
 *
 * Retrieval, ranking and truncation used to happen together inside the gateway, so a narrow `limit`
 * decided the answer before variant resolution ever ran. Now the gateway retrieves this wider window,
 * resolution and the blocklist run over all of it, and the result is narrowed to the model's own
 * `limit` afterwards — the ordering ARCHITECTURE.md's lifecycle table always claimed.
 *
 * The floor matters more than the multiplier; the ceiling bounds retrieval cost, since these are
 * id-only reads.
 *
 * ## The floor was 20, and one family fitting was not enough
 *
 * Raised to 50 on 2026-09-02. The old floor was sized so that ONE family with several variants fit
 * inside the window whole, or ranking's in-stock bias could hide the sold-out unit. A shopper asking
 * about a kind of product needs several families to fit.
 *
 * Relevance ranking clusters a family's variants adjacently — they share a name and a description —
 * so a few large families fill the window between them and every other family becomes **unreachable
 * rather than merely ranked lower**. {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier} can
 * only diversify what retrieval brought back, and `SearchProductsTool` is explicit that nothing
 * downstream can recover a unit retrieval dropped.
 *
 * Measured on the staging shop: *"What is the cheapest jersey"* retrieved 20 candidates that were
 * **11 Club Jersey variants and 9 Thermal Jersey variants — two families, 20 of 20 slots**. The shop's
 * third jersey family never entered the window, so the answer omitted it, and it was the cheapest one.
 * Plain *"zeig mir Jerseys"* was wrong the same way; the superlative only made it visible.
 *
 * A floor of 50 fits that catalogue's three jersey families (30 units) whole. It is a wider net rather
 * than a cure: one family with more than 50 variants still saturates it, and the structural fix is
 * family-aware retrieval — grouping by Shopware's `displayGroup` when no option selections are in play,
 * so the window counts families rather than units. That changes what retrieval means, and it is not
 * done here. See {@see \Swag\AssistantStarterKit\Tests\Core\Tool\SearchFamilySaturationTest}.
 *
 * ## Its own class
 *
 * `SearchProductsTool` sits at this project's 400-line file ceiling, and the reasoning above is longer
 * than the arithmetic it explains — the same seam, and the same reason, as {@see PriceSort} and
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProductNameIndex}.
 */
final class CandidateWindow
{
    private const MULTIPLIER = 4;

    /**
     * With every limit `search_products` accepts (1-8) the multiplier stays under this floor, so the
     * window is 50 in practice today. The multiplier is kept rather than deleted: it is what would
     * widen the window again if {@see self::MAX} ever rises.
     */
    private const MIN = 50;

    private const MAX = 50;

    private function __construct() {}

    public static function for(int $requestedLimit): int
    {
        return min(self::MAX, max($requestedLimit * self::MULTIPLIER, self::MIN));
    }

    /**
     * The widest this window ever gets, for a caller merging several passes into one.
     *
     * {@see \Swag\AssistantStarterKit\Core\Retrieval\CandidateInterleave} caps the merge of a
     * multi-term search with it: each term retrieved its own window, and the merged set must not
     * exceed what one window costs, or three terms would triple the candidates the pipeline resolves.
     */
    public static function ceiling(): int
    {
        return self::MAX;
    }
}
