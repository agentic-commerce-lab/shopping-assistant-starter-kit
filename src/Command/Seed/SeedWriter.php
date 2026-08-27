<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Content\Product\DataAbstractionLayer\StatesUpdater;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\InheritanceUpdater;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only code in this feature that calls `EntityRepository::create()`. Everything upstream (Tasks
 * 1–6) is pure array-building; this class exists so the DAL boundary is exactly one small, unit-test-
 * exempt file, matching how `Core/Commerce/Dal` confines Shopware's own DAL types.
 *
 * `EntityIndexerRegistry::DISABLE_INDEXING` is toggled around every write, matching Shopware's
 * demodata generators. Product inheritance and state backfills run explicitly inside that window;
 * removing the state only restores normal handling for later writes and does not index these writes.
 */
final readonly class SeedWriter
{
    private const PRODUCT_BATCH_SIZE = 200;

    public function __construct(
        private InheritanceUpdater $inheritanceUpdater,
        private StatesUpdater $statesUpdater,
    ) {}

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
            self::withIndexingDisabled($context, function () use ($productRepository, $batch, $context): void {
                $productRepository->create($batch, $context);

                $productIds = self::productIds($batch);
                $this->inheritanceUpdater->update(ProductDefinition::ENTITY_NAME, $productIds, $context);
                $this->statesUpdater->update($productIds, $context);
            });
            $io->progressAdvance(\count($batch));
        }

        $io->progressFinish();
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<string>
     */
    private static function productIds(array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = self::productId($product);
        }

        foreach ($products as $product) {
            if (!array_key_exists('children', $product)) {
                continue;
            }

            \assert(\is_array($product['children']), description: 'Product children must be a list of payloads.');

            foreach (array_keys($product['children']) as $key) {
                \assert(
                    array_key_exists($key, $product['children']),
                    description: 'Every enumerated product child must exist.',
                );
                \assert(\is_array($product['children'][$key]), description: 'Every product child must be a payload.');
                $ids[] = self::productId($product['children'][$key]);
            }
        }

        return $ids;
    }

    /** @param array<array-key, mixed> $product */
    private static function productId(array $product): string
    {
        \assert(\is_string($product['id'] ?? null), description: 'Every product payload must carry an ID.');

        return $product['id'];
    }

    /**
     * Every DAL write in this class runs inside this same window, matching how
     * `vendor/shopware/core/Framework/Demodata/Generator/{ProductGenerator,CategoryGenerator}.php`
     * toggle indexing around their own writes.
     */
    private static function withIndexingDisabled(Context $context, callable $write): void
    {
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        try {
            $write();
        } finally {
            $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
        }
    }
}
