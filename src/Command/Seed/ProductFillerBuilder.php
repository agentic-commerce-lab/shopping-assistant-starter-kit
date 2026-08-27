<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The 3,600 generated filler products — the volume the traps (Task 2) sit inside. Mirrors
 * `FashionCatalogGenerator::filler()`'s index arithmetic (the first `sideLeafCount()` indices fill
 * Brand/Season/Occasion one apiece, the rest cycle the garment leaves) so the seeded shop's product
 * distribution across categories matches the fixture's, not a differently-shaped approximation of it.
 *
 * A glibc-constants LCG, not `random_int()` — same reasoning `FashionCatalogGenerator` gives: a
 * deterministic sequence keeps two runs of this plan (and its test) identical.
 *
 * Declares the three repeated parameter shapes this class and {@see ProductPlan} both take
 * (`CategoryTreePlan::build()['idsByPath']`, `PropertyGroupPlan::build()['optionIds' |
 * 'sizeOptionIds']`) once, as named aliases `ProductPlan` imports — rather than each `@param` block
 * spelling out the same nested generics twice, which is what previously made `jscpd` flag this
 * file's `build()` docblock+signature as a near-verbatim clone of `ProductPlan::build()`'s.
 *
 * @phpstan-type CategoryIdsByPath array<string, string>
 * @phpstan-type PropertyOptionIds array<string, array<string, string>>
 * @phpstan-type SizeOptionIds array<string, string>
 */
final class ProductFillerBuilder
{
    public const COUNT = 3_600;

    private const SEED = 20_260_827;

    private function __construct() {}

    /**
     * @param CategoryIdsByPath  $categoryIdsByPath
     * @param PropertyOptionIds  $optionIds
     * @param SizeOptionIds      $sizeOptionIds
     *
     * @return list<array<string, mixed>>
     */
    public static function build(array $categoryIdsByPath, array $optionIds, array $sizeOptionIds, string $taxId): array
    {
        $state = self::SEED;
        // Intentionally not `return (($state * ...) & ...)` (mago's `inline-variable-return`
        // literal suggestion) — that would drop the mutation of the by-reference `$state` and
        // break the sequence: every call must both update and return the new state.
        $next = static function () use (&$state): int {
            return $state = (($state * 1_103_515_245) + 12_345) & 0x7FFF_FFFF;
        };

        $colours = array_keys($optionIds['Colour']);
        $materials = array_keys($optionIds['Material']);
        $sideLeaves = FashionSeedTaxonomy::sideLeafCount();

        $products = [];
        for ($index = 0; $index < self::COUNT; ++$index) {
            $leaf = $index < $sideLeaves
                ? FashionSeedTaxonomy::sideLeaf($index)
                : FashionSeedTaxonomy::garmentLeaf($index - $sideLeaves);

            $path = implode('/', $leaf['path']);
            $categoryId = $categoryIdsByPath[$path] ?? null;
            \assert($categoryId !== null, $path);

            $id = SeedId::forPath('product', 'filler/' . $index);
            $price = round(19.0 + ((float) ($next() % 28_000) / 100.0), precision: 2);
            $colour = $colours[$next() % \count($colours)];
            $material = $materials[$next() % \count($materials)];

            $product = [
                'id' => $id,
                'productNumber' => 'FW-' . strtoupper(substr($id, offset: 0, length: 12)),
                'name' => \sprintf('%s %04d', $leaf['name'], $index),
                'description' => \sprintf('%s in a considered cut.', $leaf['name']),
                'price' => SizeFamily::grossPrice($price),
                'taxId' => $taxId,
                'active' => true,
                'stock' => $next() % 12,
                'categories' => [['id' => $categoryId]],
                'properties' => [['id' => $optionIds['Colour'][$colour]], ['id' => $optionIds['Material'][$material]]],
            ];

            if (($index % 5) !== 0) {
                $family = SizeFamily::build($id, $product['productNumber'], $price, $sizeOptionIds, $next());
                $product['children'] = $family['children'];
                $product['configuratorSettings'] = $family['configuratorSettings'];
            }

            $products[] = $product;
        }

        return $products;
    }
}
