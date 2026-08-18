<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Scope exclusion over a list of {@see ProductCard} sellable units: blocked
 * product ids, blocked/excluded/included categories and a minimum description
 * word count, for {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway}.
 *
 * Blocking by product id matches both the unit's own id and its `parentId`,
 * because a variant-bearing product has no unit keyed by the product id
 * itself — only its variants are sellable units. Without matching `parentId`
 * too, blocking a variant-bearing product by its own id would do nothing.
 */
final class FixtureScopeFilter
{
    private function __construct() {}

    /**
     * @param array<string, ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function apply(array $units, CatalogScope $scope): array
    {
        return array_values(array_filter($units, static fn(ProductCard $unit): bool => self::isInScope($unit, $scope)));
    }

    private static function isInScope(ProductCard $unit, CatalogScope $scope): bool
    {
        if (
            \in_array($unit->id, $scope->blockedProductIds, strict: true)
            || \in_array($unit->parentId, $scope->blockedProductIds, strict: true)
        ) {
            return false;
        }

        if (
            array_intersect($unit->categoryPath, $scope->blockedCategoryIds) !== []
            || array_intersect($unit->categoryPath, $scope->excludeCategoryIds) !== []
        ) {
            return false;
        }

        if (
            $scope->includeCategoryIds !== []
            && array_intersect($unit->categoryPath, $scope->includeCategoryIds) === []
        ) {
            return false;
        }

        return (
            $scope->minDescriptionWords <= 0
            || self::descriptionWordCount($unit->description) >= $scope->minDescriptionWords
        );
    }

    private static function descriptionWordCount(?string $description): int
    {
        if ($description === null || trim($description) === '') {
            return 0;
        }

        $words = preg_split('/\s+/', trim($description));

        return \count($words === false ? [] : $words);
    }
}
