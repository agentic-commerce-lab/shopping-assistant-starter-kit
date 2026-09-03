<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Puts a requested price ordering back, because the database threw it away.
 *
 * ## The defect this exists for
 *
 * Measured 2026-09-03 against a real shop. With `sort: price_asc` proven present in the trace, the
 * candidate window came back **37.58 → 267.58 → 33.10 → 118.51 → 42.48**. Not ascending, and not
 * close: the ordering had no effect at all.
 *
 * `DalCriteriaBuilder` adds `FieldSorting('cheapestPrice', 'ASC')` and, for a keyword search, also
 * calls `Criteria::setTerm()`. Shopware's `CriteriaQueryBuilder` then adds its own `_score` sorting
 * for the term — *"Sort by _score primarily if the criteria has a score query or search term"* — and
 * relevance wins. So `PriceSort` never worked against the DAL. It works against
 * `FixtureCommerceGateway`, which sorts in PHP, which is exactly why `SearchPriceSortTest` was green
 * throughout and the defect reached a shopper: the mechanism was proven on the one gateway that does
 * not have the problem.
 *
 * ## Why in PHP, and why that is cheap
 *
 * The same reasoning {@see StatedBudget} gives for post-filtering a price range: the price the
 * shopper is shown is decided after the query — customer-group prices, rule prices, B2B pricing are
 * applied to the loaded entity — so a database ordering could not be authoritative even if it
 * survived the term. Ordering here uses the very numbers the cards will show, which is the only
 * ordering a shopper can check against the evidence beside it.
 *
 * It costs nothing: this runs over the candidate window, fifty cards at most, already in memory.
 *
 * ## The database ordering cannot be repaired, only replaced
 *
 * Measured on the same shop after a full `dal:refresh:index --only=product.indexer`: **2,896 of
 * 3,617 parent products have `cheapest_price_accessor` NULL**, and that column is what the DAL's
 * `cheapestPrice` sorting reads. So `ORDER BY cheapestPrice` orders mostly NULLs and lands wherever
 * MySQL likes — not an indexing backlog, that is the steady state for families whose variants do not
 * price apart. A database ordering that is arbitrary for four products in five is not one to build a
 * superlative on.
 *
 * ## What this does NOT fix, stated rather than discovered later
 *
 * The candidate window is still chosen by **relevance**, so this orders the fifty most relevant
 * matches and answers "the cheapest of those" — not "the cheapest in the shop". Measured live on 264
 * coats: before, the reply named a coat at 155.71 with the true cheapest at 21.97; after, three
 * samples named 21.97, 24.30 and 25.74. Right by a wide margin and still not exact.
 *
 * Closing that gap means either a wider window when a superlative is asked, or a database ordering
 * that works — and the second needs `PriceSort::field()` to stop naming an accessor that is
 * usually NULL. Both are economics decisions about retrieval cost, so neither is guessed at here.
 *
 * ## Ties keep relevance, for free
 *
 * Two products at the same price stay in the order retrieval gave them, which is the honest
 * tiebreaker — and it needs no bookkeeping: PHP's sorts have been **stable since 8.0**, and this
 * plugin requires 8.2. An earlier version of this class carried each card's position through the
 * comparison to guarantee that by hand; it bought nothing and cost the class the complexity budget.
 */
final class OrderedByPrice
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<ProductCard>
     */
    public static function apply(array $cards, ProductQuery $query): array
    {
        if ($query->sort === null) {
            return $cards;
        }

        $ascending = $query->sort === PriceSort::Ascending;

        usort($cards, static fn(ProductCard $left, ProductCard $right): int => $ascending
            ? $left->price <=> $right->price
            : $right->price <=> $left->price);

        return $cards;
    }
}
