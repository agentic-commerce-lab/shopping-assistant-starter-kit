<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * A gateway that can say how many products a query matches, independently of how many it returns.
 *
 * **Deliberately a separate interface rather than a method on {@see CommerceGatewayInterface}**, for
 * the same reason as {@see BatchProductLookup} and {@see FamilyVariantLookup}: that one is marked
 * `@api Public extension point`, so adding a method breaks every gateway a merchant has written.
 * Implementing this is opt-in, and a gateway that does not gets today's behaviour unchanged.
 *
 * ## Why it exists
 *
 * `search_products` already reports a `matched` figure, and its own comment concedes what that figure
 * really is: *"a floor, not a census, whenever the candidate window filled up"*. The window is
 * `MAX_CANDIDATES = 50`, so on a large catalogue the assistant sees "at least 50" whether the true
 * answer is 50 or 500 — and cannot tell a shortlist from a sample.
 *
 * Measured on the 15,218-unit fashion catalogue: `dress` reports `matched: 32`, `dress + suit` reports
 * `matched: 50, more: true`. The second number is the cap, not the catalogue.
 *
 * That distinction is the whole difference between two answers a shopper experiences very
 * differently: *"here are all six occasion dresses"* and *"here are four of three hundred"*. Only the
 * first is a shortlist; the second needs the assistant to say so, or to narrow.
 *
 * ## What an implementation owes the caller
 *
 * The count must be of the SAME set `search()` would retrieve for the same query and scope —
 * everything the scope allows, before the candidate window and before the model's own limit. A count
 * that included blocked products would tell the model the shop is bigger than the shopper may see.
 *
 * It must be cheap. Shopware can answer it without fetching rows via a
 * `Criteria::addAggregation(new CountAggregation(...))`, read back through
 * `AggregationResultCollection::get()`, which is the only reason this is worth asking on every
 * search rather than only when something looks large. **Not `Criteria::setTotalCountMode
 * (TOTAL_COUNT_MODE_EXACT)`** — that read as correct against an in-memory fixture and returned `1`
 * for every non-empty match on a real Shopware 6.7 instance regardless of the true count, measured
 * in `docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md` ("Match-count finding") and
 * fixed the same way in {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCommerceGateway}, the
 * one implementation of this `@api` interface this project ships.
 */
interface MatchCountReader
{
    /**
     * How many products `$query` matches under `$scope`, ignoring its limits.
     *
     * Returns the exact count. An implementation that cannot be exact should not implement this
     * interface at all: a second inexact number beside `matched` would be worse than one, because a
     * caller could not tell which of them to trust.
     */
    public function countMatches(ProductQuery $query, CatalogScope $scope): int;
}
