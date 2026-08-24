<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Narrows a result set to the category the shopper is browsing.
 *
 * Its own class rather than a branch in {@see FixtureQueryFilter}: that class is already at the
 * irreducible end of the complexity rule (ruling R12), and this is a different kind of predicate
 * anyway — a constraint from where the shopper is standing, not a clause they expressed.
 */
final class FixtureCategoryFilter
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function apply(array $units, ?string $categoryId): array
    {
        if ($categoryId === null) {
            return $units;
        }

        return array_values(array_filter($units, static fn(ProductCard $unit): bool => \in_array(
            $categoryId,
            $unit->categoryPath,
            strict: true,
        )));
    }
}
