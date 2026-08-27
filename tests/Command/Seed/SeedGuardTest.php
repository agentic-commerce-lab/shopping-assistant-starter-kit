<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\RefreshIndexEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;
use Swag\AssistantStarterKit\Command\Seed\MarkerCategoryStore;
use Swag\AssistantStarterKit\Command\Seed\SeedGuard;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * `Shopware\Core\Framework\Context` is a plain, dependency-free value object (unlike
 * `SalesChannelContext`, `Criteria`, or `SalesChannelProductEntity`, which the seam rule confines to
 * `Core/Commerce/Dal`) — it carries no DAL and needs no container, so it is safe to construct directly
 * in a unit test the way this file does.
 */
final class SeedGuardTest extends TestCase
{
    public function testNotSeededWhenTheMarkerIsAbsent(): void
    {
        $guard = $this->guard(new class implements MarkerCategoryStore {
            public function exists(string $id, Context $context): bool
            {
                return false;
            }

            public function create(string $id, string $parentId, string $name, Context $context): void {}
        });

        self::assertFalse($guard->alreadySeeded(Context::createDefaultContext()));
    }

    public function testAlreadySeededWhenTheMarkerExists(): void
    {
        $guard = $this->guard(new class implements MarkerCategoryStore {
            public function exists(string $id, Context $context): bool
            {
                return $id === SeedGuard::markerId();
            }

            public function create(string $id, string $parentId, string $name, Context $context): void {}
        });

        self::assertTrue($guard->alreadySeeded(Context::createDefaultContext()));
    }

    public function testMarkSeededCreatesTheMarkerUnderTheGivenParent(): void
    {
        $created = null;
        $guard = $this->guard(new class($created) implements MarkerCategoryStore {
            public function __construct(
                private mixed &$captured,
            ) {}

            public function exists(string $id, Context $context): bool
            {
                return false;
            }

            public function create(string $id, string $parentId, string $name, Context $context): void
            {
                $this->captured = [$id, $parentId, $name];
            }
        });

        $guard->markSeeded('root0000000000000000000000000000', Context::createDefaultContext());

        self::assertSame([SeedGuard::markerId(), 'root0000000000000000000000000000', SeedGuard::MARKER_NAME], $created);
    }

    public function testMarkerIdIsStable(): void
    {
        self::assertSame(SeedGuard::markerId(), SeedGuard::markerId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SeedGuard::markerId());
    }

    public function testMarkSeededRefreshesRequiredIndexesBeforeCreatingTheMarker(): void
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

        $guard = new SeedGuard($store, $registry, $dispatcher);
        $guard->markSeeded('root0000000000000000000000000000', Context::createDefaultContext());

        self::assertSame(['index', 'event', 'marker'], $calls);
    }

    public function testFailedIndexRefreshDoesNotCreateTheMarker(): void
    {
        $created = false;
        $store = new class($created) implements MarkerCategoryStore {
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

        $registry = $this->createStub(EntityIndexerRegistry::class);
        $registry->method('index')->willThrowException(new \RuntimeException('index failed'));
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $guard = new SeedGuard($store, $registry, $dispatcher);

        try {
            $guard->markSeeded('root0000000000000000000000000000', Context::createDefaultContext());
            self::fail('Expected the index failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('index failed', $exception->getMessage());
        }

        self::assertFalse($created);
    }

    private function guard(MarkerCategoryStore $store): SeedGuard
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        return new SeedGuard($store, $this->createStub(EntityIndexerRegistry::class), $dispatcher);
    }
}
