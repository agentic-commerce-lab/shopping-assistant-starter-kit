<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates the seed: guard check, tax lookup, the three plan builders (Tasks 4–6), the three writes
 * ({@see SeedWriter}), then synchronous indexing and the marker ({@see SeedGuard::markSeeded()}) —
 * the marker is written last, deliberately, so a run that fails partway through is visibly
 * unfinished on the next invocation rather than silently guarded.
 */
final readonly class SeedRunner
{
    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out 2, same shape as DalCommerceGateway's: an orchestrator
    // constructor injecting the guard, the writer and the three repositories it hands to that
    // writer, plus the connection the tax lookup reads. Six collaborators for six responsibilities
    // named in this class's own docblock — the alternative is a parameter object that exists only
    // to satisfy the linter, not to mean anything on its own.
    public function __construct(
        private SeedGuard $guard,
        private SeedWriter $writer,
        private EntityRepository $categoryRepository,
        private EntityRepository $propertyGroupRepository,
        private EntityRepository $productRepository,
        private Connection $connection,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in the migration and ShopInfo
     *     Doctrine call sites in this codebase: a tax lookup that cannot run must fail loudly rather
     *     than seed unpriced products.
     */
    public function run(SymfonyStyle $io, SalesChannelContext $salesChannelContext): SeedReport
    {
        $context = $salesChannelContext->getContext();

        if ($this->guard->alreadySeeded($context)) {
            throw new \RuntimeException(
                'This shop already carries the fashion seed marker — refusing to seed twice. '
                . 'Recovery is a database restore, not a re-run (see HANDOFF.md, Task A).',
            );
        }

        $taxId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM tax LIMIT 1');
        if (!\is_string($taxId) || $taxId === '') {
            throw new \RuntimeException('No tax rule exists in this shop — cannot price seeded products.');
        }

        $navigationRootId = $salesChannelContext->getSalesChannel()->getNavigationCategoryId();
        \assert(\is_string($navigationRootId), description: 'navigationCategoryId must be set on every sales channel');

        $categoryPlan = CategoryTreePlan::build($navigationRootId);
        $propertyPlan = PropertyGroupPlan::build();
        $products = ProductPlan::build(
            $categoryPlan['idsByPath'],
            $propertyPlan['optionIds'],
            $propertyPlan['sizeOptionIds'],
            $taxId,
            $salesChannelContext->getSalesChannelId(),
        );

        $this->writer->writeCategories($io, $this->categoryRepository, $categoryPlan['tree'], $context);
        $this->writer->writePropertyGroups($io, $this->propertyGroupRepository, $propertyPlan['groups'], $context);
        $this->writer->writeProducts($io, $this->productRepository, $products, $context);

        $this->guard->markSeeded($navigationRootId, $context);

        $sellableUnits = 0;
        foreach ($products as $product) {
            $children = $product['children'] ?? [];
            $sellableUnits += \is_array($children) && $children !== [] ? \count($children) : 1;
        }

        return new SeedReport(
            categoryCount: \count($categoryPlan['idsByPath']),
            propertyGroupCount: \count($propertyPlan['groups']),
            productCount: \count($products),
            sellableUnits: $sellableUnits,
        );
    }
}
