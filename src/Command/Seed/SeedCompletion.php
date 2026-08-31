<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\RefreshIndexEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Completes a successful seed only after its catalogue indexes and refresh event finish.
 */
final readonly class SeedCompletion
{
    /** @var list<string> */
    private const REQUIRED_INDEXERS = ['category.indexer', 'product.indexer'];

    /** @var list<string> */
    private const REQUIRED_ENTITIES = ['category', 'product'];

    public function __construct(
        private EntityIndexerRegistry $indexerRegistry,
        private EventDispatcherInterface $eventDispatcher,
        private SeedGuard $guard,
    ) {}

    public function complete(string $navigationRootId, Context $context): void
    {
        $this->reindex();
        $this->guard->markSeeded($navigationRootId, $context);
    }

    /**
     * The indexing half, without the marker.
     *
     * **Split out because a re-run needs one and not the other.** The bike seeder's `--update` mode
     * writes into a shop that is already marked, so calling {@see self::complete()} would re-mark it —
     * but it still changes product data, and Shopware resolves a variant's inherited properties
     * through the product index. Gating the whole call on the marker left the index stale, which had
     * to be repaired with `dal:refresh:index` by hand. See
     * {@see \Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedMode::indexes()}.
     *
     * `complete()` still does both, so the fashion seeder is unchanged.
     */
    public function reindex(): void
    {
        $this->indexerRegistry->index(false, [], self::REQUIRED_INDEXERS);
        $this->eventDispatcher->dispatch(new RefreshIndexEvent(true, [], self::REQUIRED_ENTITIES));
    }
}
