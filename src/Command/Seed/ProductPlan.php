<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;

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
    public static function build(
        array $categoryIdsByPath,
        array $optionIds,
        array $sizeOptionIds,
        SeedTax $tax,
        string $salesChannelId,
    ): array {
        // Collected, not thrown-on-first-hit: every unresolved path is named in one error below,
        // before writeCategories()/writePropertyGroups()/writeProducts() ever run, rather than a
        // future trap's bad path surfacing mid-batch after some products are already written.
        $unresolvedPaths = [];

        $traps = [];
        foreach (FashionSeedTraps::all() as $trap) {
            $built = self::trapToPayload($trap, $categoryIdsByPath, $optionIds, $sizeOptionIds, $tax);
            $traps[] = $built['product'];
            if ($built['unresolvedPath'] !== null) {
                $unresolvedPaths[] = $built['unresolvedPath'];
            }
        }

        $filler = ProductFillerBuilder::build($categoryIdsByPath, $optionIds, $sizeOptionIds, $tax, $unresolvedPaths);

        if ($unresolvedPaths !== []) {
            $distinct = array_values(array_unique($unresolvedPaths));

            throw new \RuntimeException(\sprintf(
                'The fashion seed plan references %d unresolved category path(s) — no write has '
                . 'started. Check FashionSeedTraps/FashionSeedTaxonomy against '
                . 'CategoryTreePlan::TRAP_LEAF_NAMES: %s',
                \count($distinct),
                implode(', ', $distinct),
            ));
        }

        $products = [...$traps, ...$filler];

        return array_map(static function (array $product) use ($salesChannelId): array {
            $product['visibilities'] = [[
                'salesChannelId' => $salesChannelId,
                'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
            ]];

            return $product;
        }, $products);
    }

    /**
     * Returns the payload alongside `$trap`'s category path, only when that path failed to resolve —
     * a tuple rather than a by-reference collector parameter, to keep this method's own parameter
     * count under the same limit `SeedRunner`'s constructor was just brought under. {@see self::build()}
     * collects every trap's and filler product's unresolved path before deciding whether to throw.
     *
     * @param array{id: string, name: string, description: string, price: float, categoryPath: list<string>, properties: array<string, list<string>>, sizes: bool} $trap
     * @param CategoryIdsByPath  $categoryIdsByPath
     * @param PropertyOptionIds  $optionIds
     * @param SizeOptionIds      $sizeOptionIds
     *
     * @return array{product: array<string, mixed>, unresolvedPath: ?string}
     */
    private static function trapToPayload(
        array $trap,
        array $categoryIdsByPath,
        array $optionIds,
        array $sizeOptionIds,
        SeedTax $tax,
    ): array {
        $path = implode('/', $trap['categoryPath']);
        $categoryId = $categoryIdsByPath[$path] ?? null;

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
            'price' => SizeFamily::grossPrice($trap['price'], $tax->rate),
            'taxId' => $tax->id,
            'active' => true,
            'stock' => 6,
            'categories' => [['id' => $categoryId]],
            'properties' => $properties,
        ];

        if ($trap['sizes']) {
            $family = SizeFamily::build(
                $product['id'],
                $product['productNumber'],
                SizeFamily::grossPrice($trap['price'], $tax->rate),
                $sizeOptionIds,
                3,
            );
            $product['children'] = $family['children'];
            $product['configuratorSettings'] = $family['configuratorSettings'];
        }

        return ['product' => $product, 'unresolvedPath' => $categoryId === null ? $path : null];
    }
}
