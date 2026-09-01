<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * What retrieval produced for ONE {@see ShopperIntent}: the candidate window, whatever note the
 * option retries produced, the built query, and whether the window filled up.
 *
 * A DTO rather than an array shape because {@see IntentRetrieval} is called once per search term now,
 * and four parallel arrays keyed by term index is the shape that goes wrong silently.
 *
 * `$buildResult` travels with the cards because the caller needs its `canonicalSelections` for variant
 * resolution — the catalogue's own spelling of the options the shopper named, not the model's casing.
 * See `VariantSelectionFilterResolver` (Finding I1).
 */
final readonly class IntentCandidates
{
    /** @param list<ProductCard> $cards */
    public function __construct(
        public array $cards,
        public ?string $note,
        public QueryBuildResult $buildResult,
        /**
         * The candidate window filled exactly to its limit, so `matched` is a floor rather than a
         * census (T4). Measured before variant resolution, because the gateway's limit applied to
         * that set and resolution can replace a parent card with a variant card.
         */
        public bool $windowSaturated,
        /**
         * Budget enforcement dropped at least one card, which means the SQL `price` range and the
         * shopper's own prices disagree — so any count computed by the database over that range is
         * unreliable for this shopper. See {@see StatedBudget} and {@see ExactMatchCount}.
         */
        public bool $budgetNarrowed = false,
    ) {}
}
