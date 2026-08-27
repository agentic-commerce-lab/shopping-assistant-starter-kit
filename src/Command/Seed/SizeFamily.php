<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * The five-size variant family every sized product in this catalogue carries — shared by
 * {@see ProductPlan} (traps) and {@see ProductFillerBuilder} (filler), the same way the fixture's
 * `FashionCatalogGenerator::sizeFamily()` and `FashionTrapProducts::sizes()` both build five variants
 * and would otherwise duplicate the shape twice here too.
 */
final class SizeFamily
{
    private function __construct() {}

    /**
     * @param array<string, string> $sizeOptionIds `PropertyGroupPlan::build()['sizeOptionIds']`.
     *
     * @return array{children: list<array<string, mixed>>, configuratorSettings: list<array<string, mixed>>}
     */
    public static function build(
        string $parentId,
        string $parentNumber,
        float $price,
        array $sizeOptionIds,
        int $stockSeed,
    ): array {
        $children = [];
        $configuratorSettings = [];
        $offset = 0;

        foreach ($sizeOptionIds as $size => $optionId) {
            $childId = SeedId::forPath('product-variant', $parentId . '/' . $size);
            $children[] = [
                'id' => $childId,
                'productNumber' => $parentNumber . '-' . strtolower($size),
                'price' => self::grossPrice($price),
                'stock' => ($stockSeed + $offset) % 9,
                'options' => [['id' => $optionId]],
            ];
            $configuratorSettings[] = ['optionId' => $optionId];
            ++$offset;
        }

        return ['children' => $children, 'configuratorSettings' => $configuratorSettings];
    }

    /**
     * The one-price-entry `price` array every product and variant payload in this catalogue
     * carries — shared here (rather than repeated in {@see ProductFillerBuilder} and
     * {@see ProductPlan}, both of which build a top-level product's own price the same way) to
     * keep the shape written once.
     *
     * @return list<array{currencyId: string, gross: float, net: float, linked: bool}>
     */
    public static function grossPrice(float $price): array
    {
        return [[
            'currencyId' => \Shopware\Core\Defaults::CURRENCY,
            'gross' => $price,
            'net' => $price,
            'linked' => true,
        ]];
    }
}
