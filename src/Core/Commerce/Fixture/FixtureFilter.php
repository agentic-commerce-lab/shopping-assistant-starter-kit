<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Scope exclusion, query filtering, term matching, sorting and facet building over
 * a list of {@see ProductCard} sellable units, for {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway}.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 *
 * The task brief sanctions exactly one split for the fixture gateway — index
 * building into FixtureIndex, filtering into this class — and no further
 * structure. Scope exclusion, query filtering, term matching, sorting and facet
 * building are five distinct concerns that legitimately need their own small
 * methods; splitting further would invent structure the brief does not call for.
 */
final class FixtureFilter
{
    private function __construct() {}

    /**
     * @param array<string, ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function inScope(array $units, CatalogScope $scope): array
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

    /**
     * @param list<ProductCard> $units
     *
     * @return list<ProductCard>
     */
    public static function applyQuery(array $units, ProductQuery $query): array
    {
        foreach ($query->filters as $filter) {
            $units = array_values(array_filter($units, static fn(ProductCard $unit): bool => self::matchesFilter(
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

        return \array_slice($units, offset: 0, length: $query->limit);
    }

    private static function matchesTerm(ProductCard $unit, string $term): bool
    {
        $needle = strtolower($term);

        return (
            str_contains(strtolower($unit->name), $needle)
            || str_contains(strtolower($unit->description ?? ''), $needle)
        );
    }

    private static function matchesFilter(ProductCard $unit, FilterClause $filter): bool
    {
        $fieldValue = self::fieldValue($unit, $filter->field);

        return match ($filter->operator) {
            FilterOperator::Equals => \is_array($fieldValue)
                ? \in_array($filter->value, $fieldValue, strict: true)
                : $fieldValue === $filter->value,
            FilterOperator::Contains => \is_array($fieldValue)
                ? \in_array($filter->value, $fieldValue, strict: true)
                : str_contains(strtolower((string) $fieldValue), strtolower((string) $filter->value)),
            FilterOperator::Range => (\is_int($fieldValue) || \is_float($fieldValue))
                && \is_array($filter->value)
                && self::withinBounds($fieldValue, $filter->value),
        };
    }

    /** @param array<array-key, mixed> $bounds */
    private static function withinBounds(int|float $fieldValue, array $bounds): bool
    {
        $gte = self::numericBound($bounds, 'gte');
        $lte = self::numericBound($bounds, 'lte');
        $gt = self::numericBound($bounds, 'gt');
        $lt = self::numericBound($bounds, 'lt');

        return (
            ($gte === null || $fieldValue >= $gte)
            && ($lte === null || $fieldValue <= $lte)
            && ($gt === null || $fieldValue > $gt)
            && ($lt === null || $fieldValue < $lt)
        );
    }

    /** @param array<array-key, mixed> $bounds */
    private static function numericBound(array $bounds, string $key): int|float|null
    {
        $value = $bounds[$key] ?? null;

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    private static function fieldValue(ProductCard $unit, string $field): mixed
    {
        if (str_starts_with($field, 'properties.')) {
            return $unit->properties[substr($field, \strlen('properties.'))] ?? [];
        }

        return match ($field) {
            'price' => $unit->price,
            'stock' => $unit->stock,
            'name' => $unit->name,
            'description' => $unit->description,
            'categoryPath' => $unit->categoryPath,
            default => null,
        };
    }

    /** @param list<ProductCard> $units */
    public static function buildFacets(array $units): FacetSet
    {
        $prices = array_map(static fn(ProductCard $unit): float => $unit->price, $units);

        $facets = [
            new Facet(
                'price',
                FacetType::Range,
                min: $prices === [] ? null : min($prices),
                max: $prices === [] ? null : max($prices),
            ),
            new Facet(
                'categoryPath',
                FacetType::Terms,
                values: self::uniqueValues($units, static fn(ProductCard $u): array => $u->categoryPath),
            ),
        ];

        foreach (self::propertyGroupNames($units) as $group) {
            $facets[] = new Facet(
                \sprintf('properties.%s', $group),
                FacetType::Terms,
                values: self::uniqueValues($units, static fn(ProductCard $u): array => $u->properties[$group] ?? []),
            );
        }

        return new FacetSet($facets);
    }

    /**
     * @param list<ProductCard> $units
     *
     * @return list<string>
     */
    private static function propertyGroupNames(array $units): array
    {
        $groups = [];
        foreach ($units as $unit) {
            foreach (array_keys($unit->properties) as $group) {
                $groups[$group] = true;
            }
        }

        return array_keys($groups);
    }

    /**
     * @param list<ProductCard>            $units
     * @param callable(ProductCard): list<string> $extractor
     *
     * @return list<string>
     */
    private static function uniqueValues(array $units, callable $extractor): array
    {
        $values = [];
        foreach ($units as $unit) {
            foreach ($extractor($unit) as $value) {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }
}
