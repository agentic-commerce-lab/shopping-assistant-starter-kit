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
     * @param array{id: string, number: string} $parent Bundled to keep the parameter count at
     *   this repo's `mago.toml` `excessive-parameter-list` threshold (5) — the brief's literal
     *   signature (`string $parentId, string $parentNumber, ...`) is 6 positional parameters and
     *   fails that gate at `error` level. `$parentId` and `$parentNumber` were already always
     *   passed together by both call sites, so bundling them is behavior-preserving.
     * @param array<string, string> $sizeOptionIds `PropertyGroupPlan::build()['sizeOptionIds']`.
     *
     * @return array{children: list<array<string, mixed>>, configuratorSettings: list<array<string, mixed>>}
     */
    public static function build(
        array $parent,
        float $price,
        string $taxId,
        array $sizeOptionIds,
        int $stockSeed,
    ): array {
        $children = [];
        $configuratorSettings = [];
        $offset = 0;

        foreach ($sizeOptionIds as $size => $optionId) {
            $childId = SeedId::forPath('product-variant', $parent['id'] . '/' . $size);
            $children[] = [
                'id' => $childId,
                'productNumber' => $parent['number'] . '-' . strtolower($size),
                'price' => [[
                    'currencyId' => \Shopware\Core\Defaults::CURRENCY,
                    'gross' => $price,
                    'net' => $price,
                    'linked' => true,
                ]],
                'stock' => ($stockSeed + $offset) % 9,
                'options' => [['id' => $optionId]],
            ];
            $configuratorSettings[] = ['optionId' => $optionId];
            ++$offset;
        }

        return ['children' => $children, 'configuratorSettings' => $configuratorSettings];
    }
}
