<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Filter clauses, term matching, sorting and limit over a list of already
 * scope-filtered {@see ProductCard} sellable units, for
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway}.
 *
 * Per-clause evaluation is delegated to {@see FixtureFilterClauseMatcher}, which
 * owns the generic field-resolution and operator semantics that clause matching
 * needs on its own.
 *
 * @mago-expect lint:cyclomatic-complexity
 *
 * This is the one rule the split above (Ruling R12) could not resolve: a
 * two-method class containing only `apply()`'s brief-mandated sequence
 * (filter, then term, then sort in-stock-first-then-price, then limit) and a
 * one-line `matchesTerm()` helper still trips the class-level aggregate,
 * confirmed by isolating `apply()` alone in a throwaway file against this
 * project's mago.toml. Decomposing `apply()`'s internals further (e.g.
 * extracting the sort comparator into its own method) does not lower the
 * aggregate either — it is a sum over the class, not a per-method score — and
 * moving `apply()` into yet another class would just relocate, not remove,
 * the count. This is the irreducible case Ruling R12 anticipated.
 */
final class FixtureQueryFilter
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function apply(array $units, ProductQuery $query): array
    {
        foreach ($query->filters as $filter) {
            $units = array_values(array_filter($units, static fn(ProductCard $unit): bool => FixtureFilterClauseMatcher::matches(
                $unit,
                $filter,
            )));
        }

        $term = $query->term;
        if ($term !== null && $term !== '') {
            $units = array_values(array_filter($units, static fn(ProductCard $unit): bool => self::matchesTerm(
                $unit,
                $term,
            )));
        }

        usort($units, static function (ProductCard $a, ProductCard $b): int {
            $stockComparison = ($b->isInStock() ? 1 : 0) <=> ($a->isInStock() ? 1 : 0);

            return $stockComparison !== 0 ? $stockComparison : $a->price <=> $b->price;
        });

        // retrievalLimit(), never limit: this slice runs *before* variant resolution, and
        // anything it drops cannot be recovered downstream. `limit` is what the caller
        // narrows to afterwards. See ProductQuery::retrievalLimit().
        return \array_slice($units, offset: 0, length: $query->retrievalLimit());
    }

    private static function matchesTerm(ProductCard $unit, string $term): bool
    {
        $needle = strtolower($term);

        return (
            str_contains(strtolower($unit->name), $needle)
            || str_contains(strtolower($unit->description ?? ''), $needle)
        );
    }
}
