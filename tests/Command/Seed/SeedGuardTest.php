<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Command\Seed\MarkerCategoryStore;
use Swag\AssistantStarterKit\Command\Seed\SeedGuard;

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
        $guard = new SeedGuard(new class implements MarkerCategoryStore {
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
        $guard = new SeedGuard(new class implements MarkerCategoryStore {
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
        $guard = new SeedGuard(new class($created) implements MarkerCategoryStore {
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
}
