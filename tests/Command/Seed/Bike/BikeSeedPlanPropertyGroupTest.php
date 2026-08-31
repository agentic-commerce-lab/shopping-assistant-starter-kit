<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedPlan;

/**
 * How the seed treats property groups the shop already has, split from {@see BikeSeedPlanTest} for
 * mago's per-class budget.
 *
 * This is the half with the sharpest consequence. `CatalogVocabulary` puts the shop's facet values
 * in front of the model, so a seed that created a second `Colour` group beside the existing one
 * would teach the assistant two spellings for the same thing and split every colour search in half —
 * a failure that would show up as poor retrieval, nowhere near the seeder that caused it.
 */
final class BikeSeedPlanPropertyGroupTest extends TestCase
{
    private function plan(): BikeSeedPlan
    {
        return BikeSeedPlan::build(FakeShopTaxonomy::complete(), FakeShopTaxonomy::SALES_CHANNEL_ID);
    }

    /**
     * Only the values the shop is missing. Re-sending a value the group already has would either
     * duplicate the option or overwrite its translation, and neither is something a seeder should do
     * to a shop it did not create.
     */
    public function testAnExistingGroupIsExtendedRatherThanRecreated(): void
    {
        $groups = [];
        foreach ($this->plan()->propertyGroups as $group) {
            $groups[$group['name'] ?? $group['id']] = $group;
        }

        // Colour resolved every value in the fixture taxonomy, so nothing about it needs writing.
        self::assertArrayNotHasKey('Colour', $groups);
    }

    public function testAGroupTheShopDoesNotHaveIsCreatedWithAllItsOptions(): void
    {
        $plan = BikeSeedPlan::build(FakeShopTaxonomy::empty(), FakeShopTaxonomy::SALES_CHANNEL_ID, strict: false);

        $created = [];
        foreach ($plan->propertyGroups as $group) {
            $name = $group['name'] ?? null;
            $options = $group['options'] ?? [];
            self::assertIsString($name);
            self::assertIsArray($options);
            $created[$name] = \count($options);
        }

        self::assertSame(\count(BikeCatalogue::propertyGroups()['Speed'] ?? []), $created['Speed'] ?? 0);
    }
}
