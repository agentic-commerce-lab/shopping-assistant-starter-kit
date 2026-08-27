<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\SeedId;

/**
 * Every id this feature writes comes from here rather than `Uuid::randomHex()` — Global Constraints:
 * a re-run that slips past {@see \Swag\AssistantStarterKit\Command\Seed\SeedGuard} attempts to recreate
 * the same rows rather than silently doubling the catalogue, and a diff between two runs of the plan
 * classes is meaningful instead of noise.
 */
final class SeedIdTest extends TestCase
{
    public function testIdIsThirtyTwoLowercaseHexChars(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SeedId::forPath('category', 'Women/Dresses'));
    }

    public function testSamePathIsStable(): void
    {
        self::assertSame(SeedId::forPath('category', 'Women/Dresses'), SeedId::forPath('category', 'Women/Dresses'));
    }

    public function testDifferentPathsDiffer(): void
    {
        self::assertNotSame(SeedId::forPath('category', 'Women/Dresses'), SeedId::forPath('category', 'Women/Tops'));
    }

    /** Namespaces exist so a category path and a product id can never collide even if the raw strings coincide. */
    public function testDifferentNamespacesDifferForTheSamePath(): void
    {
        self::assertNotSame(
            SeedId::forPath('category', 'fw-false-friend'),
            SeedId::forPath('product', 'fw-false-friend'),
        );
    }
}
