<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;

/**
 * Reads the shop's category tree through the DAL, one level at a time.
 *
 * Takes the {@see SalesChannelContext} as a parameter rather than holding a provider, so it is unit
 * testable without a request — the same shape every other reader in this namespace uses.
 *
 * ## Two queries, and why not one
 *
 * The categories come back from the category repository; whether each one holds any available product
 * does not, because a category row knows nothing about product availability. So a second query asks the
 * product repository which of these ids have at least one available product, in a single terms
 * aggregation. `categoriesRo` is the ancestor-inclusive association, which is what makes one
 * aggregation answer for a whole level: a product in `Women > Dresses > Maxi` counts under all three.
 *
 * Both queries fetch no rows worth speaking of — the first is bounded by {@see self::MAX_NODES} and the
 * second sets a limit of one and reads only buckets.
 */
final readonly class DalCategoryTreeReader
{
    /**
     * The most nodes one read returns.
     *
     * Forty. The local shop's top level has seven children and the generated fashion tree fourteen, so
     * this truncates neither; it exists to stop a pathological tree turning one read into a catalogue
     * dump. **Measured against a shop with a wide level, 2026-08-27**: the seeded fashion catalogue's
     * `Brand` branch has exactly 40 children — this bound, not from data. It is currently harmless only
     * because {@see \Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation::of()}, the sole caller of
     * {@see self::read()}, always passes `$parentId = null` (the navigation root, 7 children) — nothing
     * in this codebase reads a deeper level yet. {@see \Swag\AssistantStarterKit\Tests\Core\Commerce\Dal\DalCategoryTreeReaderBoundsTest}
     * locks the coincidence in so a future non-root reader doesn't discover it via silent truncation.
     */
    private const MAX_NODES = 40;

    public function __construct(
        private SalesChannelRepository $categoryRepository,
        private SalesChannelRepository $productRepository,
    ) {}

    /**
     * @return list<CategoryNode>
     */
    public function read(?string $parentId, CatalogScope $scope, SalesChannelContext $context): array
    {
        $parent = $parentId ?? $context->getSalesChannel()->getNavigationCategoryId();
        $categories = $this->children($parent, $context);
        $allowed = DalCategoryScope::allowed($categories, $scope);

        if ($allowed === []) {
            return [];
        }

        return $this->toNodes($allowed, $this->idsWithProducts($allowed, $context));
    }

    /**
     * `visibleChildCount` rather than `childCount`, and `active` rather than nothing: this is a
     * shopper-facing tool, so a category hidden from the storefront navigation must not be advertised by
     * the assistant either.
     *
     * @return list<CategoryEntity>
     */
    private function children(string $parentId, SalesChannelContext $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('name'));
        $criteria->setLimit(self::MAX_NODES);

        $found = [];

        foreach ($this->categoryRepository->search($criteria, $context)->getElements() as $entity) {
            if ($entity instanceof CategoryEntity) {
                $found[] = $entity;
            }
        }

        return $found;
    }

    /**
     * Which of these categories hold at least one available product, in one aggregation.
     *
     * @param list<CategoryEntity> $categories
     *
     * @return array<string, true>
     */
    private function idsWithProducts(array $categories, SalesChannelContext $context): array
    {
        return DalCategoryProducts::withProducts(
            array_map(static fn(CategoryEntity $category): string => $category->getId(), $categories),
            $this->productRepository,
            $context,
        );
    }

    /**
     * @param list<CategoryEntity> $categories
     * @param array<string, true>  $stocked
     *
     * @return list<CategoryNode>
     */
    private function toNodes(array $categories, array $stocked): array
    {
        $nodes = [];

        foreach ($categories as $category) {
            $name = $category->getTranslation('name') ?? $category->getName();

            $nodes[] = new CategoryNode(
                id: $category->getId(),
                name: \is_string($name) ? $name : '',
                path: DalCategoryBreadcrumb::of($category),
                hasProducts: isset($stocked[$category->getId()]),
                hasChildren: $category->getVisibleChildCount() > 0,
            );
        }

        return $nodes;
    }
}
