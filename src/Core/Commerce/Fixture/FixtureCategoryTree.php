<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Derives a category tree from the `categoryPath` the fixture's products carry.
 *
 * **A node's id is its path, joined.** The fixture has no category entities and therefore no ids to
 * hand out, and inventing surrogate ones would need state this class does not have. A path string is
 * stable across runs, is as opaque to the model as a product id, and makes a failing test readable. Its
 * one requirement: a category name must not contain {@see self::SEPARATOR}, which no committed or
 * generated fixture name does.
 *
 * **`hasProducts` is always true here, and that is correct rather than lazy.** A node exists in this
 * implementation only because a product's path put it there, so there is no such thing as an empty
 * fixture category. The DAL reader has to answer the question properly, because a real shop has plenty.
 */
final class FixtureCategoryTree
{
    public const SEPARATOR = '/';

    private function __construct() {}

    /**
     * @param list<ProductCard> $units
     *
     * @return list<CategoryNode>
     */
    public static function childrenOf(array $units, ?string $parentId): array
    {
        $prefix = $parentId === null ? [] : explode(self::SEPARATOR, $parentId);
        $depth = \count($prefix);

        /** @var array<string, array<string, true>> $children keyed by node id, then by child name */
        $nodes = [];

        foreach ($units as $unit) {
            $path = $unit->categoryPath;

            if (!self::isUnder($path, $prefix, $depth)) {
                continue;
            }

            $id = implode(self::SEPARATOR, \array_slice($path, offset: 0, length: $depth + 1));
            $nodes[$id] ??= [];

            // Read into a local first: indexing a list gives the analyzer `string|null`, and using it
            // straight as an array key would type the child map as `array<string|null, true>`.
            $child = $path[$depth + 1] ?? null;

            if ($child !== null) {
                $nodes[$id][$child] = true;
            }
        }

        return self::toNodes($nodes);
    }

    /**
     * @param list<string> $path
     * @param list<string> $prefix
     */
    private static function isUnder(array $path, array $prefix, int $depth): bool
    {
        return \count($path) > $depth && \array_slice($path, offset: 0, length: $depth) === $prefix;
    }

    /**
     * `ksort` on the id gives sorted-by-name order within one parent, because every id under one parent
     * shares its prefix — which is what the interface's ordering contract requires.
     *
     * @param array<string, array<string, true>> $nodes
     *
     * @return list<CategoryNode>
     */
    private static function toNodes(array $nodes): array
    {
        ksort($nodes);

        $result = [];

        foreach ($nodes as $id => $children) {
            $path = explode(self::SEPARATOR, (string) $id);

            $result[] = new CategoryNode(
                id: (string) $id,
                name: $path[\count($path) - 1] ?? (string) $id,
                path: $path,
                hasProducts: true,
                hasChildren: $children !== [],
            );
        }

        return $result;
    }
}
