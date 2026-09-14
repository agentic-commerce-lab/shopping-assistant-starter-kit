<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The shop's top-level departments, by id, so a product can say which one it belongs to.
 *
 * ## Why the top level and not the product's own category
 *
 * A product sits in a leaf — "Fahrradteile > Komponenten > Kleinteile > Schrauben & Muttern" — and
 * every level but the first describes WHAT it is, which its name says better. The first level
 * answers the only question an ambiguous word raises: which world is this for. See
 * {@see \Swag\AssistantStarterKit\Core\Tool\Departments} for the failure that makes it worth a
 * query at all.
 *
 * ## One read per turn, not one per product
 *
 * The map is tiny — a shop has a handful of departments — and every product in a turn is resolved
 * against the same one, so it is read once and passed down to {@see DalProductCardMapper}. That is
 * also why this returns a map rather than resolving a single product: the alternative is one query
 * per card, which on a fifty-candidate search is fifty queries for a string.
 *
 * The read matches {@see DalCategoryTreeReader}'s own — children of the sales channel's navigation
 * root, active only — because `browse_categories` already calls those nodes departments and the two
 * must not disagree about what the shop's sections are.
 *
 * **No scope filtering here, deliberately.** {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope}
 * decides which PRODUCTS a shopper may see, and it is applied where products are retrieved. A
 * department name attached to a product that already passed that filter reveals nothing further: if
 * the product is visible, the section it sits in is visible in the shop's own menu.
 */
final class DalDepartments
{
    /**
     * Generous, and bounded for the same reason {@see DalCategoryTreeReader::MAX_NODES} is: a
     * misconfigured navigation root can have thousands of children, and this map is held per turn.
     */
    private const MAX_DEPARTMENTS = 100;

    public function __construct(
        private readonly SalesChannelRepository $categoryRepository,
    ) {}

    /**
     * @return array<string, string> department id => its name
     */
    public function of(SalesChannelContext $context): array
    {
        $root = $context->getSalesChannel()->getNavigationCategoryId();

        if (!\is_string($root) || $root === '') {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $root));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('name'));
        $criteria->setLimit(self::MAX_DEPARTMENTS);

        $departments = [];

        foreach ($this->categoryRepository->search($criteria, $context)->getElements() as $category) {
            if (!$category instanceof CategoryEntity) {
                continue;
            }

            $name = $category->getTranslation('name');
            $name = \is_string($name) && $name !== '' ? $name : $category->getName();

            if (\is_string($name) && trim($name) !== '') {
                $departments[$category->getId()] = trim($name);
            }
        }

        return $departments;
    }
}
