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
 */
final class ProductFillerBuilder
{
    public const COUNT = 3_600;

    private const SEED = 20_260_827;

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
                'price' => [[
                    'currencyId' => \Shopware\Core\Defaults::CURRENCY,
                    'gross' => $price,
                    'net' => $price,
                    'linked' => true,
                ]],
                'taxId' => $taxId,
                'active' => true,
                'stock' => $next() % 12,
                'categories' => [['id' => $categoryId]],
                'properties' => [['id' => $optionIds['Colour'][$colour]], ['id' => $optionIds['Material'][$material]]],
            ];

            if (($index % 5) !== 0) {
                $family = SizeFamily::build(
                    ['id' => $id, 'number' => $product['productNumber']],
                    $price,
                    $taxId,
                    $sizeOptionIds,
                    $next(),
                );
                $product['children'] = $family['children'];
                $product['configuratorSettings'] = $family['configuratorSettings'];
            }

            $products[] = $product;
        }

        return $products;
    }
}
