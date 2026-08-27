<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;

/**
 * The two operations {@see SeedGuard} needs from a category repository. A dedicated interface rather
 * than `EntityRepository` directly, so the guard's branching logic — the one piece standing between a
 * re-run and a doubled catalogue — can be unit tested with a fake instead of a live container.
 */
interface MarkerCategoryStore
{
    public function exists(string $id, Context $context): bool;

    public function create(string $id, string $parentId, string $name, Context $context): void;
}
