<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\RefreshIndexEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Swag\AssistantStarterKit\Command\Seed\MarkerCategoryStore;
use Swag\AssistantStarterKit\Command\Seed\SeedCompletion;
use Swag\AssistantStarterKit\Command\Seed\SeedGuard;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SeedCompletionTest extends TestCase
{
    public function testCompleteRefreshesRequiredIndexesBeforeCreatingTheMarker(): void
    {
        $calls = [];
        $store = new class($calls) implements MarkerCategoryStore {
            public function __construct(
                private array &$calls,
            ) {}

            public function exists(string $id, Context $context): bool
            {
                return false;
            }

            public function create(string $id, string $parentId, string $name, Context $context): void
            {
                $this->calls[] = 'marker';
            }
        };

        $registry = $this->createMock(EntityIndexerRegistry::class);
        $registry
            ->expects(self::once())
            ->method('index')
            ->with(false, [], ['category.indexer', 'product.indexer'])
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'index';
            });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$calls): object {
                self::assertInstanceOf(RefreshIndexEvent::class, $event);
                self::assertTrue($event->getNoQueue());
                self::assertSame([], $event->getSkipEntities());
                self::assertSame(['category', 'product'], $event->getOnlyEntities());
                $calls[] = 'event';

                return $event;
            });

        $completion = new SeedCompletion($registry, $dispatcher, new SeedGuard($store));
        $completion->complete('root0000000000000000000000000000', Context::createDefaultContext());

        self::assertSame(['index', 'event', 'marker'], $calls);
    }

    public function testFailedIndexRefreshDoesNotCreateTheMarker(): void
    {
        $created = false;
        $failure = new \RuntimeException('index failed');
        $registry = $this->createStub(EntityIndexerRegistry::class);
        $registry->method('index')->willThrowException($failure);
        $completion = new SeedCompletion(
            $registry,
            $this->createStub(EventDispatcherInterface::class),
            new SeedGuard(self::trackingStore($created)),
        );

        try {
            $completion->complete('root0000000000000000000000000000', Context::createDefaultContext());
            self::fail('Expected the index failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($created);
    }

    public function testFailedRefreshEventDoesNotCreateTheMarker(): void
    {
        $created = false;
        $failure = new \RuntimeException('event failed');
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException($failure);
        $completion = new SeedCompletion(
            $this->createStub(EntityIndexerRegistry::class),
            $dispatcher,
            new SeedGuard(self::trackingStore($created)),
        );

        try {
            $completion->complete('root0000000000000000000000000000', Context::createDefaultContext());
            self::fail('Expected the refresh event failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($created);
    }

    private static function trackingStore(bool &$created): MarkerCategoryStore
    {
        return new class($created) implements MarkerCategoryStore {
            public function __construct(
                private bool &$created,
            ) {}

            public function exists(string $id, Context $context): bool
            {
                return false;
            }

            public function create(string $id, string $parentId, string $name, Context $context): void
            {
                $this->created = true;
            }
        };
    }
}
