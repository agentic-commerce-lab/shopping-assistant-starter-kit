<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * Whether one sellable unit matches every given {@see VariantSelection}, for
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway::resolveVariant()}.
 *
 * Split out of the gateway itself (rather than left inline, as it was before Finding
 * C1's `CatalogScope` parameter tipped that class's own aggregate cyclomatic
 * complexity over this project's threshold) to match the pattern every other
 * matching/filtering concern in this package already follows — see
 * {@see FixtureScopeFilter}, {@see FixtureQueryFilter} and {@see FixtureFilterClauseMatcher}
 * — rather than suppressing the finding.
 */
final class FixtureVariantMatcher
{
    private function __construct() {}

    /** @param list<VariantSelection> $selections */
    public static function matchesAll(ProductCard $unit, array $selections): bool
    {
        foreach ($selections as $selection) {
            if (!self::matches($unit, $selection)) {
                return false;
            }
        }

        return true;
    }

    private static function matches(ProductCard $unit, VariantSelection $selection): bool
    {
        if ($selection->group !== null) {
            return ($unit->options[$selection->group] ?? null) === $selection->option;
        }

        return \in_array($selection->option, $unit->options, strict: true);
    }
}
