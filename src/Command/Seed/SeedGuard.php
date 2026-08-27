<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\RefreshIndexEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The only defence against seeding twice. HANDOFF.md, Task A: *"Do not add `--force`: the recovery for
 * 'I seeded twice' is a database restore either way."* — there is deliberately no override here.
 *
 * The marker category is written **last**, after every DAL write, product backfill, synchronous
 * category/product index refresh and refresh event succeeds. A run that dies partway through leaves
 * no marker rather than falsely claiming success. Partial writes are not cleaned up automatically;
 * recovering from one is the database restore the quote above already names.
 */
final readonly class SeedGuard
{
    public const MARKER_NAME = 'Fashion Seed Marker — do not delete';

    /** @var list<string> */
    private const REQUIRED_INDEXERS = ['category.indexer', 'product.indexer'];

    /** @var list<string> */
    private const REQUIRED_ENTITIES = ['category', 'product'];

    public function __construct(
        private MarkerCategoryStore $store,
        private EntityIndexerRegistry $indexerRegistry,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public static function markerId(): string
    {
        return SeedId::forPath('marker', 'fashion-seed');
    }

    public function alreadySeeded(Context $context): bool
    {
        return $this->store->exists(self::markerId(), $context);
    }

    public function markSeeded(string $navigationRootId, Context $context): void
    {
        $this->indexerRegistry->index(false, [], self::REQUIRED_INDEXERS);
        $this->eventDispatcher->dispatch(new RefreshIndexEvent(true, [], self::REQUIRED_ENTITIES));
        $this->store->create(self::markerId(), $navigationRootId, self::MARKER_NAME, $context);
    }
}
