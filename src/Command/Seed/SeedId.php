<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

/**
 * Turns a stable string into a Shopware-valid 32-hex-char id.
 *
 * Deterministic rather than `Uuid::randomHex()` — see Global Constraints in the seeder plan. A sha256
 * hash truncated to 32 hex chars keeps the collision risk at the same order as a real UUID4 while
 * costing nothing to reproduce from a path string alone, which is what lets {@see CategoryTreePlan}
 * build parent/child links before anything is written.
 */
final class SeedId
{
    private function __construct() {}

    public static function forPath(string $namespace, string $path): string
    {
        return substr(hash('sha256', $namespace . '::' . $path), 0, 32);
    }
}
