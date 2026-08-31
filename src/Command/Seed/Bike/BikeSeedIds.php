<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

use Swag\AssistantStarterKit\Command\Seed\SeedId;

/**
 * Every id this seeder derives rather than reads, in one place.
 *
 * Split out of {@see BikeSeedPlan} for mago's per-class budget, and the seam holds its own: these are
 * the ids the seed *creates*, as opposed to the ones {@see DalShopTaxonomyReader} finds. Deriving
 * them from a stable string rather than randomising means a re-seed of a wiped shop lands on exactly
 * the same ids — the same property {@see SeedId} exists for on the fashion side.
 *
 * The namespaces are prefixed `bike-` so the two seeders cannot collide on a shop that has run both.
 */
final class BikeSeedIds
{
    private function __construct() {}

    public static function product(string $number): string
    {
        return SeedId::forPath('bike-product', $number);
    }

    public static function category(string $path): string
    {
        return SeedId::forPath('bike-category', $path);
    }

    public static function group(string $name): string
    {
        return SeedId::forPath('bike-property-group', $name);
    }

    public static function option(string $group, string $value): string
    {
        return SeedId::forPath('bike-property-option', $group . '/' . $value);
    }

    /** The last segment of a `Parent/Child` path, which is the name the category is written under. */
    public static function leaf(string $path): string
    {
        $parts = explode('/', $path);

        return end($parts) ?: $path;
    }
}
