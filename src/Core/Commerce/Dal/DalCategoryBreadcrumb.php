<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Category\CategoryEntity;

/**
 * A category's ancestry as plain names, for {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode::$path}.
 *
 * Its own class because reading a breadcrumb and enforcing {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope}
 * are different jobs, and holding both put `DalCategoryScope` over this project's complexity budget.
 *
 * Shopware's `getBreadcrumb()` includes the navigation root, which is a structural node rather than a
 * department a shopper would recognise — but it is filtered by the caller's own scope rather than here,
 * because which node counts as the root is a property of the sales channel, not of the category.
 */
final class DalCategoryBreadcrumb
{
    private function __construct() {}

    /** @return list<string> the ancestors' names, this category's own last */
    public static function of(CategoryEntity $category): array
    {
        $names = [];

        foreach ($category->getBreadcrumb() as $name) {
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
