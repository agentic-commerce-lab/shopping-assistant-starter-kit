<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

/**
 * What a keyword search could plausibly match in this catalogue, and which products match a word.
 *
 * Its own class rather than private helpers on {@see FashionTrapPresenceTest}: the scan is two nested
 * loops over every product and every property value, and mago sums cyclomatic complexity across a
 * class's methods — carrying it in the test class pushed that class over the threshold of ten while
 * every individual method was trivial.
 *
 * The boundary is also the right one. {@see FashionCatalogQuery} answers structural questions (which
 * product, which node, how many units); this answers the only question the occasion traps are about:
 * **what words does this catalogue actually contain?**
 *
 * @phpstan-import-type FashionCatalogue from FashionCatalogGenerator
 * @phpstan-import-type FashionProduct from FashionCatalogGenerator
 */
final class FashionCatalogText
{
    private function __construct() {}

    /**
     * Every product whose searchable text contains `$word`, case-insensitively.
     *
     * @param FashionCatalogue $catalogue
     *
     * @return list<string> product ids, in catalogue order
     */
    public static function idsMentioning(array $catalogue, string $word): array
    {
        $needle = strtolower($word);
        $ids = [];

        foreach ($catalogue['products'] as $product) {
            if (str_contains(strtolower(self::searchableText($product)), $needle)) {
                $ids[] = $product['id'];
            }
        }

        return $ids;
    }

    /**
     * Everything about one product a keyword search could match: its name, its description, its
     * category names, and its property groups and values.
     *
     * Property GROUP names are included as well as values, because a group is a word the catalogue
     * contains — and the trap is about words the catalogue contains, not about where they sit.
     *
     * @param FashionProduct $product
     */
    public static function searchableText(array $product): string
    {
        $words = [$product['name'], $product['description'] ?? '', ...$product['categoryPath']];

        foreach ($product['properties'] as $group => $values) {
            $words[] = $group;

            foreach ($values as $value) {
                $words[] = $value;
            }
        }

        return implode(' ', $words);
    }
}
