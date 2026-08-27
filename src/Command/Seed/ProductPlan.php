<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The full seeded product list: the 17 named traps (Task 2) plus 3,600 generated filler products
 * ({@see ProductFillerBuilder}). Counts are measured (see the seeder plan's Context table) and
 * asserted as constants below, not computed inline and trusted.
 */
final class ProductPlan
{
    public const PRODUCT_COUNT = 3_617;

    public const SELLABLE_UNITS = 15_201;

    private function __construct() {}

    /**
     * @param array<string, string>                $categoryIdsByPath
     * @param array<string, array<string, string>>  $optionIds
     * @param array<string, string>                 $sizeOptionIds
     *
     * @return list<array<string, mixed>>
     */
    public static function build(array $categoryIdsByPath, array $optionIds, array $sizeOptionIds, string $taxId): array
    {
        $traps = array_map(static fn(array $trap): array => self::trapToPayload(
            $trap,
            $categoryIdsByPath,
            $optionIds,
            $sizeOptionIds,
            $taxId,
        ), FashionSeedTraps::all());

        return [...$traps, ...ProductFillerBuilder::build($categoryIdsByPath, $optionIds, $sizeOptionIds, $taxId)];
    }

    /**
     * @param array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool} $trap
     * @param array<string, string>                                                                                                                                 $categoryIdsByPath
     * @param array<string, array<string, string>>                                                                                                                 $optionIds
     * @param array<string, string>                                                                                                                                $sizeOptionIds
     *
     * @return array<string, mixed>
     */
    private static function trapToPayload(
        array $trap,
        array $categoryIdsByPath,
        array $optionIds,
        array $sizeOptionIds,
        string $taxId,
    ): array {
        $path = implode('/', $trap['categoryPath']);
        $categoryId = $categoryIdsByPath[$path] ?? null;
        \assert($categoryId !== null, $path);

        $properties = [];
        foreach ($trap['properties'] as $group => $values) {
            foreach ($values as $value) {
                $properties[] = ['id' => $optionIds[$group][$value]];
            }
        }

        $product = [
            'id' => SeedId::forPath('product', $trap['id']),
            'productNumber' => 'FW-' . strtoupper($trap['id']),
            'name' => $trap['name'],
            'description' => $trap['description'],
            'price' => [[
                'currencyId' => \Shopware\Core\Defaults::CURRENCY,
                'gross' => $trap['price'],
                'net' => $trap['price'],
                'linked' => true,
            ]],
            'taxId' => $taxId,
            'active' => true,
            'stock' => 6,
            'categories' => [['id' => $categoryId]],
            'properties' => $properties,
        ];

        if ($trap['sizes']) {
            $family = SizeFamily::build(
                ['id' => $product['id'], 'number' => $product['productNumber']],
                $trap['price'],
                $taxId,
                $sizeOptionIds,
                3,
            );
            $product['children'] = $family['children'];
            $product['configuratorSettings'] = $family['configuratorSettings'];
        }

        return $product;
    }
}
