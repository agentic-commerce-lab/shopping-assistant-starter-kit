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
        $this->indexerRegistry->index(false, [], self::REQUIRED_INDEXERS);
        $this->eventDispatcher->dispatch(new RefreshIndexEvent(true, [], self::REQUIRED_ENTITIES));
        $this->guard->markSeeded($navigationRootId, $context);
    }
}
