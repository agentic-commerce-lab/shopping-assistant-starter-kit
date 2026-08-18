<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Facet computation over a list of already scope-filtered {@see ProductCard}
 * sellable units: a `price` Range facet, a `categoryPath` Terms facet and one
 * `properties.<Group>` Terms facet per property group, for
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway}.
 */
final class FixtureFacetBuilder
{
    private function __construct() {}

    /** @param list<ProductCard> $units */
    public static function build(array $units): FacetSet
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
     * @param list<ProductCard>                   $units
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
