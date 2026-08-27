<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The property groups this catalogue's products carry: three non-variant groups that populate
 * `DalCommerceGateway::facets()`'s `properties` aggregation (Colour, Material, Pattern), and one
 * variant-defining `Size` group that populates its `options` aggregation and drives
 * {@see ProductPlan}'s size families.
 *
 * `FashionSeedTraps`'s colours and materials must all resolve here —
 * {@see PropertyGroupPlanTest::testEveryTrapColourAndMaterialResolves} is the seam test; a colour named
 * in a trap but missing here is a `ProductPlan` write with no option id to assign.
 */
final class PropertyGroupPlan
{
    private const NAMESPACE = 'property';

    /** Covers every colour {@see FashionSeedTraps} names plus enough spread for filler products. */
    private const COLOURS = [
        'Black',
        'Ivory',
        'Navy',
        'Camel',
        'Sage',
        'Rust',
        'Blush',
        'Slate',
        'Olive',
        'Burgundy',
        'Cobalt',
        'Emerald',
        'Lilac',
        'Charcoal',
        'Stone',
        'Cream',
    ];

    /** Covers every material {@see FashionSeedTraps} names plus enough spread for filler products. */
    private const MATERIALS = [
        'Cotton',
        'Linen',
        'Silk',
        'Wool',
        'Viscose',
        'Satin',
        'Velvet',
        'Jersey',
        'Tencel',
        'Silver',
    ];

    private const PATTERNS = ['Plain', 'Striped', 'Floral', 'Checked', 'Polka Dot'];

    private const SIZES = ['XS', 'S', 'M', 'L', 'XL'];

    private function __construct() {}

    /**
     * @return array{groups: list<array<string, mixed>>, optionIds: array<string, array<string, string>>, sizeOptionIds: array<string, string>}
     */
    public static function build(): array
    {
        $optionIds = [];
        $groups = [
            self::group('Colour', self::COLOURS, $optionIds),
            self::group('Material', self::MATERIALS, $optionIds),
            self::group('Pattern', self::PATTERNS, $optionIds),
        ];

        $ignored = [];
        $groups[] = self::group('Size', self::SIZES, $ignored);

        $sizeOptionIds = [];
        foreach (self::SIZES as $size) {
            $sizeOptionIds[$size] = SeedId::forPath(self::NAMESPACE, 'Size/' . $size);
        }

        return ['groups' => $groups, 'optionIds' => $optionIds, 'sizeOptionIds' => $sizeOptionIds];
    }

    /**
     * @param list<string>                          $values
     * @param array<string, array<string, string>>  $optionIds
     *
     * @return array<string, mixed>
     */
    private static function group(string $name, array $values, array &$optionIds): array
    {
        $options = [];
        foreach ($values as $value) {
            $id = SeedId::forPath(self::NAMESPACE, $name . '/' . $value);
            $optionIds[$name][$value] = $id;
            $options[] = ['id' => $id, 'name' => $value];
        }

        return ['id' => SeedId::forPath(self::NAMESPACE, $name), 'name' => $name, 'options' => $options];
    }
}
