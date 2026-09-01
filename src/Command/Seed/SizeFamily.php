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
        array $price,
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
                'price' => $price,
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
     * `$price` is the **gross** figure, as the name says, and `net` is derived from it rather than
     * copied. Until 2026-09-01 both fields carried the same number, which is a pair that cannot both
     * be true at any non-zero tax rate. On a gross-display storefront it looked right, which is why
     * it survived; B2B pricing is what exposed it. Shopware Commercial's Individual Pricing applies
     * its percentage to the **net** figure and then re-derives gross, so a merchant-configured +50%
     * surcharge rendered as 59 × 1.5 × 1.19 = 105.32 — an effective +78%, measured on the staging
     * shop, and indistinguishable from a Commercial bug. See {@see \Swag\AssistantStarterKit\Tests\Command\Seed\SeededPriceTest}.
     *
     * @param float $taxRate percent, e.g. `19.0` — the rate of the `taxId` the same payload carries
     *
     * @return list<array{currencyId: string, gross: float, net: float, linked: bool}>
     */
    public static function grossPrice(float $price, float $taxRate): array
    {
        return [[
            'currencyId' => \Shopware\Core\Defaults::CURRENCY,
            'gross' => $price,
            // Rounded to the currency's own precision: an unrounded net leaves Shopware storing a
            // figure with more decimals than any price field displays, and the admin then shows a
            // net that does not multiply back to the gross beside it.
            'net' => round($price / (1 + ($taxRate / 100)), 2),
            'linked' => true,
        ]];
    }
}
