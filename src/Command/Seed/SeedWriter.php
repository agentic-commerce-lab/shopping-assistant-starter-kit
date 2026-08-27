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
        self::withIndexingDisabled($context, static fn() => $categoryRepository->create($tree, $context));
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
        self::withIndexingDisabled($context, static fn() => $propertyGroupRepository->create($groups, $context));
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
            self::withIndexingDisabled($context, static fn() => $productRepository->create($batch, $context));
            $io->progressAdvance(\count($batch));
        }

        $io->progressFinish();
    }

    /**
     * Every DAL write in this class runs inside this same window, matching how
     * `vendor/shopware/core/Framework/Demodata/Generator/{ProductGenerator,CategoryGenerator}.php`
     * toggle indexing around their own writes.
     */
    private static function withIndexingDisabled(Context $context, callable $write): void
    {
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        $write();
        $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
    }
}
