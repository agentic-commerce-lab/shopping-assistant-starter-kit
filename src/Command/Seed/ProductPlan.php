<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The full seeded product list: the 17 named traps (Task 2) plus 3,600 generated filler products
 * ({@see ProductFillerBuilder}). Counts are measured (see the seeder plan's Context table) and
 * asserted as constants below, not computed inline and trusted.
 *
 * @phpstan-import-type CategoryIdsByPath from ProductFillerBuilder
 * @phpstan-import-type PropertyOptionIds from ProductFillerBuilder
 * @phpstan-import-type SizeOptionIds from ProductFillerBuilder
 */
final class ProductPlan
{
    public const PRODUCT_COUNT = 3_617;

    public const SELLABLE_UNITS = 15_201;

    private function __construct() {}

    /**
     * Forwards straight to {@see self::trapToPayload()} and {@see ProductFillerBuilder::build()} —
     * both declare the full `CategoryIdsByPath`/`PropertyOptionIds`/`SizeOptionIds` parameter shapes
     * on their own signatures (this method never indexes into any of the three itself), so repeating
     * them here would only restate what those two already say — but this signature still has to
     * declare the same three shapes itself, otherwise mago only sees plain `array` here and cannot
     * prove the two forwarding calls below satisfy the stricter shapes those methods require.
     *
     * @param CategoryIdsByPath  $categoryIdsByPath
     * @param PropertyOptionIds  $optionIds
     * @param SizeOptionIds      $sizeOptionIds
     * @return list<array<string, mixed>> one DAL write payload per top-level product, traps first
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
     * @param CategoryIdsByPath  $categoryIdsByPath
     * @param PropertyOptionIds  $optionIds
     * @param SizeOptionIds      $sizeOptionIds
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
            'price' => SizeFamily::grossPrice($trap['price']),
            'taxId' => $taxId,
            'active' => true,
            'stock' => 6,
            'categories' => [['id' => $categoryId]],
            'properties' => $properties,
        ];

        if ($trap['sizes']) {
            $family = SizeFamily::build($product['id'], $product['productNumber'], $trap['price'], $sizeOptionIds, 3);
            $product['children'] = $family['children'];
            $product['configuratorSettings'] = $family['configuratorSettings'];
        }

        return $product;
    }
}
