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
 * The only code in this feature that calls `EntityRepository::create()` — but not the only DAL-touching
 * class in it any more: {@see DalMarkerCategoryStore} also writes through the DAL, and {@see SeedRunner}
 * itself holds a `Doctrine\DBAL\Connection` for the tax lookup. What this class still owns alone is the
 * three `EntityRepository` instances the seed writes through and the indexing-state handling around
 * every write; {@see SeedRunner} is a thin orchestrator that never touches a repository directly. Unlike
 * `DalMarkerCategoryStore`, this class needs no live Shopware container to test — `EntityRepository` is
 * mocked, not constructed — so it is exercised directly by {@see SeedWriterTest}.
 *
 * `EntityIndexerRegistry::DISABLE_INDEXING` is toggled around every write, matching Shopware's
 * demodata generators. Product inheritance and state backfills run explicitly inside that window;
 * removing the state only restores normal handling for later writes and does not index these writes.
 */
final readonly class SeedWriter
{
    private const PRODUCT_BATCH_SIZE = 200;

    public function __construct(
        private EntityRepository $categoryRepository,
        private EntityRepository $propertyGroupRepository,
        private EntityRepository $productRepository,
        private InheritanceUpdater $inheritanceUpdater,
        private StatesUpdater $statesUpdater,
    ) {}

    /**
     * @param list<array<string, mixed>> $tree
     */
    public function writeCategories(SymfonyStyle $io, array $tree, Context $context): void
    {
        $io->writeln('Writing category tree…');
        self::withIndexingDisabled($context, fn() => $this->categoryRepository->create($tree, $context));
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    public function writePropertyGroups(SymfonyStyle $io, array $groups, Context $context): void
    {
        $io->writeln('Writing property groups…');
        self::withIndexingDisabled($context, fn() => $this->propertyGroupRepository->create($groups, $context));
    }

    /**
     * @param list<array<string, mixed>> $products
     */
    public function writeProducts(SymfonyStyle $io, array $products, Context $context): void
    {
        $io->progressStart(\count($products));

        foreach (array_chunk($products, self::PRODUCT_BATCH_SIZE) as $batch) {
            self::withIndexingDisabled($context, function () use ($batch, $context): void {
                $this->productRepository->create($batch, $context);

                $productIds = self::productIds($batch);
                $this->inheritanceUpdater->update(ProductDefinition::ENTITY_NAME, $productIds, $context);
                // Mirrors Shopware 6.7's ProductGenerator; StatesUpdater removal is a 6.8 migration point.
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
        $indexingWasDisabled = $context->hasState(EntityIndexerRegistry::DISABLE_INDEXING);
        $context->addState(EntityIndexerRegistry::DISABLE_INDEXING);
        try {
            $write();
        } finally {
            // Only remove the state if we are the ones who added it; a caller that already had
            // indexing disabled owns clearing it, not us.
            if (!$indexingWasDisabled) {
                $context->removeState(EntityIndexerRegistry::DISABLE_INDEXING);
            }
        }
    }
}
