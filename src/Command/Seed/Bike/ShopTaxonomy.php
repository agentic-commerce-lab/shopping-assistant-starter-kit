<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * What the shop already has, resolved to ids, as the plan builder needs to see it.
 *
 * **A value object rather than four arrays passed around**, because every one of these is a lookup
 * that can come back empty, and an empty lookup is the difference between "attach to the shop's own
 * Colour group" and "create a second Colour group beside it". Keeping them together lets
 * {@see BikeSeedPlan} report every unresolved reference in one error, and lets its tests build a
 * shop state without a database.
 *
 * Read by {@see \Swag\AssistantStarterKit\Command\Seed\Bike\DalShopTaxonomyReader} in the shipped
 * command; constructed directly in tests.
 */
final readonly class ShopTaxonomy
{
    /**
     * @param array<string, string>                $categoryIdsByName   e.g. `Jerseys` => id
     * @param array<string, string>                $propertyGroupIdsByName
     * @param array<string, array<string, string>> $optionIds           group => value => id
     * @param array<string, string>                $manufacturerIdsByName
     * @param array<string, string>                $productIdsByNumber  e.g. `sk-101` => id — only the
     *                                                                  shop's own products are ever
     *                                                                  looked up here, to add the
     *                                                                  properties they lack
     */
    // @mago-expect lint:excessive-parameter-list
    // Six independent lookups against one shop, and no two of them belong together: a category id and
    // a manufacturer id have nothing in common but the database they came from. Grouping any of them
    // would invent an object whose only purpose was this signature. The alternative considered and
    // rejected was a second value object for `productIdsByNumber` alone, which would have pushed the
    // same parameter onto BikeSeedPlan::build() instead of removing it.
    public function __construct(
        public array $categoryIdsByName,
        public array $propertyGroupIdsByName,
        public array $optionIds,
        public array $manufacturerIdsByName,
        public string $taxId,
        public array $productIdsByNumber = [],
    ) {}
}
