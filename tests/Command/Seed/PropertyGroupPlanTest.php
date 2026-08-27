<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\FashionSeedTraps;
use Swag\AssistantStarterKit\Command\Seed\PropertyGroupPlan;

final class PropertyGroupPlanTest extends TestCase
{
    public function testBuildsFourGroups(): void
    {
        $result = PropertyGroupPlan::build();

        $names = array_map(static fn(array $group): mixed => $group['name'], $result['groups']);
        self::assertSame(['Colour', 'Material', 'Pattern', 'Size'], $names);
    }

    public function testSizeOptionsCoverTheFiveSizesEverySeededVariantUses(): void
    {
        $result = PropertyGroupPlan::build();

        self::assertSame(['XS', 'S', 'M', 'L', 'XL'], array_keys($result['sizeOptionIds']));

        foreach ($result['sizeOptionIds'] as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
    }

    /**
     * Every colour/material value a trap product names (Task 2) must resolve here, or {@see ProductPlan}
     * would have no option id to assign it — this is the seam between the two duplicated word lists.
     */
    public function testEveryTrapColourAndMaterialResolves(): void
    {
        $result = PropertyGroupPlan::build();

        foreach (FashionSeedTraps::all() as $product) {
            foreach ($product['properties'] as $group => $values) {
                foreach ($values as $value) {
                    self::assertArrayHasKey($value, $result['optionIds'][$group], "$group:$value");
                }
            }
        }
    }

    /**
     * The `Size` group's write payload (the option ids inside `groups`) and `sizeOptionIds` (what
     * {@see ProductPlan} assigns to variants) must name the exact same ids for the same size, or a
     * variant would carry an option id that matches nothing actually written to the database.
     */
    public function testSizeOptionIdsMatchTheSizeGroupsWritePayload(): void
    {
        $result = PropertyGroupPlan::build();

        $sizeGroup = $result['groups'][3];
        self::assertSame('Size', $sizeGroup['name']);

        $idsFromGroupPayload = [];
        foreach ($sizeGroup['options'] as $option) {
            $idsFromGroupPayload[$option['name']] = $option['id'];
        }

        self::assertSame($idsFromGroupPayload, $result['sizeOptionIds']);
    }
}
