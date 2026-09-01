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
     * The generator loop below is what actually walks these three shapes; {@see ProductPlan::build()}
     * just forwards them here unread.
     * @param CategoryIdsByPath  $categoryIdsByPath
     * @param PropertyOptionIds  $optionIds
     * @param SizeOptionIds      $sizeOptionIds
     * @param list<string>       $unresolvedPaths appended to, never read, when a filler product's
     *     category path does not resolve — {@see ProductPlan::build()} throws once every trap and
     *     filler product has been checked, rather than failing on the first one found.
     * @return list<array<string, mixed>>
     */
    public static function build(
        array $categoryIdsByPath,
        array $optionIds,
        array $sizeOptionIds,
        SeedTax $tax,
        array &$unresolvedPaths,
    ): array {
        $state = self::SEED;
        // Intentionally not `return (($state * ...) & ...)` (mago's `inline-variable-return`
        // literal suggestion) — that would drop the mutation of the by-reference `$state` and
        // break the sequence: every call must both update and return the new state.
        $next = static function () use (&$state): int {
            return $state = (($state * 1_103_515_245) + 12_345) & 0x7FFF_FFFF;
        };

        /** @var array<string, string> $colourOptionIds `PropertyGroupPlan::build()` always populates a `Colour` group. */
        $colourOptionIds = $optionIds['Colour'];
        /** @var array<string, string> $materialOptionIds `PropertyGroupPlan::build()` always populates a `Material` group. */
        $materialOptionIds = $optionIds['Material'];

        $colours = array_keys($colourOptionIds);
        $materials = array_keys($materialOptionIds);
        $sideLeaves = FashionSeedTaxonomy::sideLeafCount();

        $products = [];
        for ($index = 0; $index < self::COUNT; ++$index) {
            $leaf = $index < $sideLeaves
                ? FashionSeedTaxonomy::sideLeaf($index)
                : FashionSeedTaxonomy::garmentLeaf($index - $sideLeaves);

            $path = implode('/', $leaf['path']);
            $categoryId = $categoryIdsByPath[$path] ?? null;
            if ($categoryId === null) {
                $unresolvedPaths[] = $path;
            }

            $id = SeedId::forPath('product', 'filler/' . $index);
            $price = round(19.0 + ((float) ($next() % 28_000) / 100.0), precision: 2);
            $colour = $colours[$next() % \count($colours)];
            $material = $materials[$next() % \count($materials)];

            $product = [
                'id' => $id,
                'productNumber' => 'FW-' . strtoupper(substr($id, offset: 0, length: 12)),
                'name' => \sprintf('%s %04d', $leaf['name'], $index),
                'description' => \sprintf('%s in a considered cut.', $leaf['name']),
                'price' => SizeFamily::grossPrice($price, $tax->rate),
                'taxId' => $tax->id,
                'active' => true,
                'stock' => $next() % 12,
                'categories' => [['id' => $categoryId]],
                'properties' => [['id' => $colourOptionIds[$colour]], ['id' => $materialOptionIds[$material]]],
            ];

            if (($index % 5) !== 0) {
                $family = SizeFamily::build(
                    $id,
                    $product['productNumber'],
                    SizeFamily::grossPrice($price, $tax->rate),
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
