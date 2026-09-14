<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/**
 * Which of the shop's departments a product belongs to, resolved without a single extra join.
 *
 * ## `categoryTree` rather than the `categories` association
 *
 * `product.category_tree` is a denormalised list of every category id a product sits in, ancestors
 * included — a plain `ListField` on the product row, marked `Inherited`, so a variant carries its
 * parent's. It arrives with the product the retrieval already fetched. Loading `categories` instead
 * would add a many-to-many join over fifty candidates to learn one string per card, which is the
 * cost {@see DalDepartments} avoids on the other side by reading the department map once per turn.
 *
 * ## The first match wins, and a product in two departments is not a problem to solve here
 *
 * A shop may file one product under several departments — a chain lubricant under both motorcycle
 * and bicycle parts — and there is no fact in the data that makes one of them the "real" one. The
 * department map arrives sorted by name, so the choice is at least stable rather than arbitrary per
 * request, and a product that genuinely belongs to two worlds is not the case the ambiguity work is
 * about: that case is two DIFFERENT products with the same name in different departments.
 *
 * Reporting several would be worse than picking one. The model is told a department so it can tell
 * products apart in a sentence; a card labelled with two departments tells the shopper less than a
 * card labelled with one, not more.
 */
final class DalProductDepartment
{
    private function __construct() {}

    /**
     * @param array<string, string> $departments id => name, from {@see DalDepartments::of()}
     *
     * @return list<string> the product's department name, or an empty list — shaped as
     *         {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard::$categoryPath} so the
     *         fixture and DAL gateways produce the same thing for
     *         {@see \Swag\AssistantStarterKit\Core\Tool\Departments} to read
     */
    public static function of(SalesChannelProductEntity $product, array $departments): array
    {
        if ($departments === []) {
            return [];
        }

        // Iterating the DEPARTMENTS, not the product's tree: `category_tree` is a denormalised
        // column whose order is an implementation detail, while the map arrives sorted by name. A
        // product in two departments must not report one of them today and the other tomorrow.
        $tree = array_flip(array_filter($product->getCategoryTree() ?? [], \is_string(...)));

        foreach ($departments as $id => $name) {
            if (isset($tree[$id])) {
                return [$name];
            }
        }

        return [];
    }
}
