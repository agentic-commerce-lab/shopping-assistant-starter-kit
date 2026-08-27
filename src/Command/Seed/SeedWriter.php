<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only code in this feature that calls `EntityRepository::create()`. Everything upstream (Tasks
 * 1–6) is pure array-building; this class exists so the DAL boundary is exactly one small, unit-test-
 * exempt file, matching how `Core/Commerce/Dal` confines Shopware's own DAL types.
 *
 * `EntityIndexerRegistry::DISABLE_INDEXING` toggled around every write, exactly as
 * `vendor/shopware/core/Framework/Demodata/Generator/{ProductGenerator,CategoryGenerator}.php` do —
 * removing the state after each batch is what re-enables Shopware's normal (queued) indexing for that
 * batch, so no separate reindex step is needed afterward.
 */
final readonly class SeedWriter
{
    private const PRODUCT_BATCH_SIZE = 200;

    /**
     * @param list<array<string, mixed>> $tree
     */
    public function writeCategories(
        SymfonyStyle $io,
        EntityRepository $categoryRepository,
        array $tree,
        Context $context,
    ): void {
        $io->writeln('Writing category tree…');
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        $categoryRepository->create($tree, $context);
        $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    public function writePropertyGroups(
        SymfonyStyle $io,
        EntityRepository $propertyGroupRepository,
        array $groups,
        Context $context,
    ): void {
        $io->writeln('Writing property groups…');
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        $propertyGroupRepository->create($groups, $context);
        $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    public function writeProducts(
        SymfonyStyle $io,
        EntityRepository $productRepository,
        array $products,
        Context $context,
    ): void {
        $io->progressStart(\count($products));

        foreach (array_chunk($products, self::PRODUCT_BATCH_SIZE) as $batch) {
            $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
            $productRepository->create($batch, $context);
            $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
            $io->progressAdvance(\count($batch));
        }

        $io->progressFinish();
    }
}
