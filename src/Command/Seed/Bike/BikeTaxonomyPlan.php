<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * The two payloads that describe the shop's shelves rather than what sits on them: the categories
 * this seed adds, and the property-group changes it needs.
 *
 * Split from {@see BikeSeedPlan} for mago's per-class budget, and it is the seam a reader wants
 * anyway — this is the half that decides how the seed *attaches* to an existing shop, which is the
 * part with the sharp edges. `BikeSeedPlan` is left with the products.
 */
final class BikeTaxonomyPlan
{
    private function __construct() {}

    /**
     * New categories only, each hanging off a parent the shop already has.
     *
     * @return list<array<string, mixed>>
     */
    public static function categories(ShopTaxonomy $shop): array
    {
        $categories = [];

        foreach (BikeCatalogue::categories() as $path => $parent) {
            $parentId = $shop->categoryIdsByName[$parent] ?? null;

            if (!\is_string($parentId)) {
                continue;
            }

            $categories[] = [
                'id' => BikeSeedIds::category($path),
                'parentId' => $parentId,
                'name' => BikeSeedIds::leaf($path),
                'active' => true,
                'displayNestedProducts' => true,
            ];
        }

        return $categories;
    }

    /**
     * A payload per group that needs writing, and nothing for a group that is already complete.
     *
     * An existing group is addressed by its own id with only the missing options attached, so the
     * DAL merges rather than replaces. A missing group is created whole, with an id derived from its
     * name so a re-seed of a wiped shop lands on the same ids.
     *
     * @return list<array<string, mixed>>
     */
    public static function propertyGroups(ShopTaxonomy $shop): array
    {
        $groups = [];

        foreach (BikeCatalogue::propertyGroups() as $name => $values) {
            $existingId = $shop->propertyGroupIdsByName[$name] ?? null;

            $missing = [];
            foreach ($values as $value) {
                if (!isset($shop->optionIds[$name][$value])) {
                    $missing[] = ['id' => BikeSeedIds::option($name, $value), 'name' => $value];
                }
            }

            if ($missing === []) {
                continue;
            }

            $groups[] = \is_string($existingId)
                ? ['id' => $existingId, 'options' => $missing]
                : [
                    'id' => BikeSeedIds::group($name),
                    'name' => $name,
                    'displayType' => 'text',
                    'sortingType' => 'position',
                    'options' => $missing,
                ];
        }

        return $groups;
    }
}
