<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * {@see MarkerCategoryStore} over the real `category` repository. Not covered by this repo's own test
 * suite — `EntityRepository` needs a live Shopware container, which `vendor/bin/phpunit` here does not
 * have (Global Constraints). {@see SeedGuardTest} covers the branching logic this class only executes
 * against; this class itself is verified by running the seed command inside the Docker shop.
 */
final readonly class DalMarkerCategoryStore implements MarkerCategoryStore
{
    public function __construct(
        private EntityRepository $categoryRepository,
    ) {}

    public function exists(string $id, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $id));
        $criteria->setLimit(1);

        return $this->categoryRepository->searchIds($criteria, $context)->getTotal() > 0;
    }

    public function create(string $id, string $parentId, string $name, Context $context): void
    {
        // Inactive: the marker must never appear in the storefront's own navigation, only exist for
        // `exists()` to find.
        $this->categoryRepository->create([[
            'id' => $id,
            'parentId' => $parentId,
            'name' => $name,
            'active' => false,
        ]], $context);
    }
}
