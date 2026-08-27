<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * The handful of lookups every fashion-catalogue test class needs.
 *
 * A helper rather than a trait or copied private methods, for the same reason
 * {@see \Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogQuery} is one: the assertions live
 * in two classes ({@see FashionCatalogGeneratorTest} for the generator's contract,
 * {@see FashionTrapPresenceTest} for the trap shapes) and a `find by id` loop in both is duplication
 * that drifts.
 *
 * @phpstan-import-type FashionCatalogue from FashionCatalogGenerator
 * @phpstan-import-type FashionProduct from FashionCatalogGenerator
 */
final class FashionCatalogQuery
{
    private function __construct() {}

    /** @return FashionCatalogue */
    public static function built(): array
    {
        return self::generator()->build();
    }

    public static function generator(int $seed = 20_260_826): FashionCatalogGenerator
    {
        return new FashionCatalogGenerator(self::smallCatalogPath(), $seed);
    }

    public static function smallCatalogPath(): string
    {
        return __DIR__ . '/../catalog.json';
    }

    /**
     * @param FashionCatalogue $catalogue
     *
     * @return FashionProduct|null
     */
    public static function find(array $catalogue, string $id): ?array
    {
        foreach ($catalogue['products'] as $product) {
            if ($product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Every product whose id starts with `$prefix`, in catalogue order.
     *
     * The traps are addressed by prefix rather than by a list of ids because that is how the eval
     * assertions address them too — see `rendered_ids_from_each` — so a test and a journey are asking
     * the same question of the same catalogue.
     *
     * @param FashionCatalogue $catalogue
     *
     * @return list<FashionProduct>
     */
    public static function withIdPrefix(array $catalogue, string $prefix): array
    {
        $matches = [];

        foreach ($catalogue['products'] as $product) {
            if (str_starts_with($product['id'], $prefix)) {
                $matches[] = $product;
            }
        }

        return $matches;
    }

    /**
     * Sellable units, not products: a family of five is five things a shopper can buy.
     *
     * @param FashionCatalogue $catalogue
     */
    public static function sellableUnits(array $catalogue): int
    {
        $units = 0;

        foreach ($catalogue['products'] as $product) {
            $units += \count($product['variants']) > 0 ? \count($product['variants']) : 1;
        }

        return $units;
    }

    /**
     * Every distinct category node in the catalogue, keyed by its path so counting is `\count()`.
     *
     * A node is every PREFIX of every product's path, not only the leaf: `Women > Dresses > Maxi
     * Dresses` is three nodes, and the tree's size is what this fixture exists to be large in.
     *
     * @param FashionCatalogue $catalogue
     *
     * @return array<string, true>
     */
    public static function categoryNodes(array $catalogue): array
    {
        $nodes = [];

        foreach ($catalogue['products'] as $product) {
            $path = [];

            foreach ($product['categoryPath'] as $segment) {
                $path[] = $segment;
                $nodes[implode('/', $path)] = true;
            }
        }

        return $nodes;
    }
}
