<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Category\CategoryEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

/**
 * Applies {@see CatalogScope} to a level of the category tree, and reads a category's breadcrumb.
 *
 * Split from {@see DalCategoryTreeReader} for the complexity budget, and because scope enforcement is
 * the part worth reading on its own: describing the tree must not become a way to enumerate what the
 * merchant chose to hide.
 */
final class DalCategoryScope
{
    private function __construct() {}

    /**
     * **Blocked ids are removed, and an include list is honoured through the category's `path`.**
     * A category is within an included set when it IS one of them or sits beneath one — Shopware stores
     * the ancestry as a `|id|id|` string, so a substring test answers it without another query. Testing
     * only the id itself would exclude the children of an included category, which is not what a
     * merchant who included a department means.
     *
     * @param list<CategoryEntity> $categories
     *
     * @return list<CategoryEntity>
     */
    public static function allowed(array $categories, CatalogScope $scope): array
    {
        $allowed = [];

        foreach ($categories as $category) {
            if (self::isAllowed($category, $scope)) {
                $allowed[] = $category;
            }
        }

        return $allowed;
    }

    private static function isAllowed(CategoryEntity $category, CatalogScope $scope): bool
    {
        if (\in_array($category->getId(), $scope->blockedCategoryIds, strict: true)) {
            return false;
        }

        if ($scope->includeCategoryIds === []) {
            return true;
        }

        return self::within($category, $scope->includeCategoryIds);
    }

    /** @param list<string> $includeCategoryIds */
    private static function within(CategoryEntity $category, array $includeCategoryIds): bool
    {
        $path = $category->getPath() ?? '';

        foreach ($includeCategoryIds as $included) {
            if ($category->getId() === $included || str_contains($path, '|' . $included . '|')) {
                return true;
            }
        }

        return false;
    }
}
