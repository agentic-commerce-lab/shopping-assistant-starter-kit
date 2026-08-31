<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed;

use Shopware\Core\Framework\Context;

/**
 * The only defence against seeding twice. HANDOFF.md, Task A: *"Do not add `--force`: the recovery for
 * 'I seeded twice' is a database restore either way."* — there is deliberately no override here.
 *
 * This class only stores the marker; {@see SeedCompletion} owns the required index/event/marker order.
 * Partial writes are not cleaned up automatically; recovering from one is the database restore the
 * quote above already names.
 */
final readonly class SeedGuard
{
    public const MARKER_NAME = 'Fashion Seed Marker — do not delete';

    /**
     * `$seed` names which catalogue this guard is protecting, and it is a constructor argument rather
     * than a constant because there is now more than one seeder.
     *
     * **The two must not share a marker.** They write disjoint catalogues into different shops for
     * different reasons — the fashion one for retrieval at scale, the bike one for a demo shop
     * somebody browses — and one marker would make seeding either refuse the other. Defaulted to the
     * fashion values so every existing call site, service definition and test keeps meaning exactly
     * what it meant.
     */
    public function __construct(
        private MarkerCategoryStore $store,
        private string $seed = 'fashion-seed',
        private string $markerName = self::MARKER_NAME,
    ) {}

    /**
     * Static for the fashion seed, which is what {@see SeedIdTest} and the service wiring refer to;
     * {@see self::currentMarkerId()} is the per-instance one this class now uses internally.
     */
    public static function markerId(): string
    {
        return SeedId::forPath('marker', 'fashion-seed');
    }

    public function currentMarkerId(): string
    {
        return SeedId::forPath('marker', $this->seed);
    }

    public function alreadySeeded(Context $context): bool
    {
        return $this->store->exists($this->currentMarkerId(), $context);
    }

    public function markSeeded(string $navigationRootId, Context $context): void
    {
        $this->store->create($this->currentMarkerId(), $navigationRootId, $this->markerName, $context);
    }
}
