<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;

/**
 * The only defence against seeding twice. HANDOFF.md, Task A: *"Do not add `--force`: the recovery for
 * 'I seeded twice' is a database restore either way."* — there is deliberately no override here.
 *
 * The marker category is written **last**, by {@see SeedRunner}, after every category, property group
 * and product write succeeds — so a run that dies partway through leaves no marker, and the next
 * invocation reports "not yet seeded" rather than falsely claiming success. That also means a failed
 * run's partial writes are not cleaned up automatically; recovering from one is the database restore
 * the quote above already names.
 */
final readonly class SeedGuard
{
    public const MARKER_NAME = 'Fashion Seed Marker — do not delete';

    public function __construct(
        private MarkerCategoryStore $store,
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
        $this->store->create(self::markerId(), $navigationRootId, self::MARKER_NAME, $context);
    }
}
