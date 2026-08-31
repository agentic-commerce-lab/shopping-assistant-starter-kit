<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * The four things every product payload needs, carried together.
 *
 * A holder rather than four parameters, because {@see BikeSeedPlan::product()} was over the
 * parameter budget this project holds itself to and the four genuinely belong together: they are all
 * "what this run resolved before it started planning", constant for the whole run, and never
 * meaningful apart from one another.
 */
final readonly class BikeSeedContext
{
    /**
     * @param array<string, string>                $categoryIds category name (or `Parent/Child`) => id
     * @param array<string, array<string, string>> $optionIds   group => value => id
     */
    private function __construct(
        public ShopTaxonomy $shop,
        public string $salesChannelId,
        public array $categoryIds,
        public array $optionIds,
    ) {}

    /**
     * Merges what the shop already has with what this seed is about to create, so a product payload
     * can look up either without caring which it got.
     *
     * That merge is the reason this is a named constructor rather than four arguments assembled at
     * the call site: "the shop's id if it has one, otherwise the id we are about to write" is a rule,
     * and a rule belongs somewhere it can be read once.
     */
    public static function resolve(ShopTaxonomy $shop, string $salesChannelId): self
    {
        return new self($shop, $salesChannelId, self::categoryIds($shop), self::optionIds($shop));
    }

    /**
     * Option ids as {@see BikeVariantFamily} needs them: the shop's own where the value exists, and
     * the id {@see BikeTaxonomyPlan} is about to create where it does not.
     *
     * @return array<string, array<string, string>>
     */
    private static function optionIds(ShopTaxonomy $shop): array
    {
        $ids = [];

        foreach (BikeCatalogue::propertyGroups() as $group => $values) {
            foreach ($values as $value) {
                $ids[$group][$value] = $shop->optionIds[$group][$value] ?? BikeSeedIds::option($group, $value);
            }
        }

        return $ids;
    }

    /**
     * Category ids by the name a product files itself under. A seeded category is addressed by its
     * full `Parent/Child` path, which is what the catalogue's `category` field carries for one it
     * creates; an existing one is addressed by its plain name.
     *
     * @return array<string, string>
     */
    private static function categoryIds(ShopTaxonomy $shop): array
    {
        $ids = $shop->categoryIdsByName;

        foreach (array_keys(BikeCatalogue::categories()) as $path) {
            $ids[$path] = BikeSeedIds::category($path);
        }

        return $ids;
    }
}
