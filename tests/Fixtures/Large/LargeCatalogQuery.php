<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * The handful of lookups both large-catalogue test classes need.
 *
 * A helper class rather than a trait or copied private methods: the assertions live in two classes
 * ({@see LargeCatalogGeneratorTest} for the generator's contract, {@see ScaleTrapPresenceTest} for
 * the trap shapes), and duplicating a `find by id` loop into both is exactly what this project's
 * duplication gate exists to catch.
 *
 * @phpstan-import-type LargeCatalogue from LargeCatalogGenerator
 * @phpstan-import-type LargeProduct from LargeCatalogGenerator
 */
final class LargeCatalogQuery
{
    private function __construct() {}

    /** @return LargeCatalogue */
    public static function built(): array
    {
        return self::generator()->build();
    }

    public static function generator(int $seed = 20_260_825): LargeCatalogGenerator
    {
        return new LargeCatalogGenerator(self::smallCatalogPath(), $seed);
    }

    public static function smallCatalogPath(): string
    {
        return __DIR__ . '/../catalog.json';
    }

    /**
     * @param LargeCatalogue $catalogue
     *
     * @return LargeProduct|null
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
     * The product with this id, or a thrown exception naming it.
     *
     * {@see self::find()} returns null for absence, which is right for the caller that is *testing*
     * absence. Every other caller goes on to read a field, and `assertNotNull()` does not narrow a
     * nullable for the analyzer — so those callers would each need a null branch that can only be
     * reached if a trap vanished. This throws instead: the test still fails, with the id in the
     * message, and nothing downstream is nullable.
     *
     * @param LargeCatalogue $catalogue
     *
     * @return LargeProduct
     */
    public static function require(array $catalogue, string $id): array
    {
        $product = self::find($catalogue, $id);

        if (null === $product) {
            throw new \RuntimeException(\sprintf('The large catalogue has no product "%s".', $id));
        }

        return $product;
    }

    /**
     * Sellable units, not products: a family of five is five things a shopper can buy, and the
     * constants this fixture exists to cross are all expressed in units.
     *
     * @param LargeCatalogue $catalogue
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
     * Every distinct `group|value` pair in the catalogue, keyed so counting is `\count()`.
     *
     * @param LargeCatalogue $catalogue
     *
     * @return array{groups: array<string, true>, pairs: array<non-empty-string, true>}
     */
    public static function vocabulary(array $catalogue): array
    {
        $groups = [];
        $pairs = [];

        foreach ($catalogue['products'] as $product) {
            foreach ($product['properties'] as $group => $values) {
                $groups[$group] = true;

                foreach ($values as $value) {
                    $pairs[$group . '|' . $value] = true;
                }
            }
        }

        return ['groups' => $groups, 'pairs' => $pairs];
    }
}
