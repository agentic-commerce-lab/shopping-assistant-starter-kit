<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterOperator;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Evaluates a single {@see FilterClause} against a {@see ProductCard} sellable
 * unit: resolves the clause's field to a value on the unit, then applies
 * `Equals`/`Range`/`Contains` semantics, for {@see FixtureQueryFilter}.
 *
 * @mago-expect lint:cyclomatic-complexity
 *
 * Generic field resolution (`fieldValue()`) plus three independent operator
 * semantics (`Equals`/`Range`/`Contains`) already sit at the smallest grain
 * this concern splits into without fragmenting a single match expression
 * across files. As with {@see FixtureQueryFilter}, the class-level aggregate
 * is a sum over methods, not a per-method score, so this is the same
 * irreducible case: confirmed the threshold is exceeded by the class as a
 * whole rather than by any single flagged method.
 */
final class FixtureFilterClauseMatcher
{
    private function __construct() {}

    public static function matches(ProductCard $unit, FilterClause $filter): bool
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
}
