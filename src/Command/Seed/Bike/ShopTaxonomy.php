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
     */
    public function __construct(
        public array $categoryIdsByName,
        public array $propertyGroupIdsByName,
        public array $optionIds,
        public array $manufacturerIdsByName,
        public string $taxId,
    ) {}
}
