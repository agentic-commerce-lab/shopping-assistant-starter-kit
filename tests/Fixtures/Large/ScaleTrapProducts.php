<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * The three trap products that are *placed* rather than generated.
 *
 * Split out of {@see LargeCatalogGenerator} because they share nothing with it: the filler needs the
 * seeded sequence and an index, these three are fixed structures whose every field is load-bearing
 * and chosen by hand. Keeping them here means the generator reads as "volume plus three placed
 * defects" rather than as one class with two unrelated halves — and it keeps both classes under this
 * project's ten-method lint threshold.
 *
 * The fourth trap, `sc-broad-term`, is deliberately **not** here: it is not a product but a property
 * of the filler — {@see ScaleTrap::BROAD_TERM_WORD} in ~500 generated names — and giving it a method
 * here would imply a fourth object that does not exist.
 *
 * Every method is static and pure. Nothing in a trap depends on the seed; that is what makes a
 * failing journey nameable (spec decision S5).
 *
 * @phpstan-import-type LargeProduct from LargeCatalogGenerator
 */
final class ScaleTrapProducts
{
    private function __construct() {}

    /**
     * Trap `sc-family-30`: thirty variants with the sold-out one last.
     *
     * The window is 20–50 candidates, so a family this size cannot fit inside it whole — which
     * `SearchProductsTool`'s own limit docblock calls the property that keeps ranking's in-stock bias
     * from hiding a sold-out unit. Here the sold-out unit is deliberately the last one generated.
     *
     * @return LargeProduct
     */
    public static function thirtyVariantFamily(): array
    {
        $variants = [];

        for ($i = 1; $i <= 30; ++$i) {
            $variants[] = [
                'id' => \sprintf('%s-v%d', ScaleTrap::FAMILY_PARENT, $i),
                'options' => ['Size' => 'Size ' . $i],
                'price' => 99.0,
                // The thirtieth is the trap. Every sibling is in stock so a correct answer about the
                // sold-out one cannot happen by accident.
                'stock' => 30 === $i ? 0 : 5,
            ];
        }

        return [
            'id' => ScaleTrap::FAMILY_PARENT,
            'name' => 'Endurance Bib Tights',
            'description' => 'Thirty sizes, one of them sold out.',
            'price' => 99.0,
            'stock' => 145,
            'url' => '/detail/' . ScaleTrap::FAMILY_PARENT,
            'categoryPath' => ['Apparel', 'Tights'],
            'properties' => [
                'Size' => array_map(static fn(int $i): string => 'Size ' . $i, range(start: 1, end: 30)),
            ],
            'variants' => $variants,
        ];
    }

    /**
     * Trap `sc-rare-option`: an option value the facet aggregation's 50-bucket cap cannot return.
     *
     * The filler spreads 60 `Shade N` values across the `Colour` group; this product adds a 61st
     * value with a real word. Whether `FacetProbe` can see it is the question.
     *
     * @return LargeProduct
     */
    public static function rareOption(): array
    {
        return [
            'id' => ScaleTrap::RARE_OPTION_PRODUCT,
            'name' => 'Randonneur Musette',
            'description' => 'One colour, and it is the sixty-first in its group.',
            'price' => 32.0,
            'stock' => 4,
            'url' => '/detail/' . ScaleTrap::RARE_OPTION_PRODUCT,
            'categoryPath' => ['Accessories', 'Bags'],
            'properties' => [ScaleTrap::RARE_OPTION_GROUP => [ScaleTrap::RARE_OPTION_VALUE]],
            'variants' => [],
        ];
    }

    /**
     * Trap `sc-deep-duplicate`: the small fixture's `fx-007` name, on a different product, deep in
     * insertion order.
     *
     * `fx-007` ("Alloy Water Bottle 750ml") and `fx-008` ("Alloy Water Bottle 750 ml", one space
     * apart) are already a near-duplicate pair by design. This makes it a trio, with the third one
     * far enough down that reaching it requires the window to be wide enough.
     *
     * @return LargeProduct
     */
    public static function deepDuplicate(): array
    {
        return [
            'id' => ScaleTrap::DEEP_DUPLICATE,
            'name' => ScaleTrap::COMMON_NAME,
            'description' => 'A third bottle with the same name, added late.',
            'price' => 21.5,
            'stock' => 2,
            'url' => '/detail/' . ScaleTrap::DEEP_DUPLICATE,
            'categoryPath' => ['Accessories', 'Bottles'],
            'properties' => [],
            'variants' => [],
        ];
    }
}
