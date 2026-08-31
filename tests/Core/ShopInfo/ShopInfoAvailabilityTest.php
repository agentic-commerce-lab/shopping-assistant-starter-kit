<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;

/**
 * Whether this shop can run shop-information retrieval at all.
 *
 * **Measured on a live shop, 2026-08-31.** The staging shop had `embeddingModel` set, so
 * `SearchShopInfoToolFactory` built the tool on every turn — and the assistant answered every message
 * with a 500: *Attempted to load class "Vectorizer" from namespace "Symfony\AI\Store\Document"*. Its
 * `vendor/` had never installed `symfony/ai-store`. The whole assistant was down, on a shop whose
 * database could not have run the feature even with the package present.
 *
 * Two independent requirements, and the shop had neither:
 *
 * 1. **The packages.** `symfony/ai-store` and `symfony/ai-maria-db-store` are `composer.json`
 *    requires, but Shopware autoloads a plugin's dependencies from the SHOP's vendor tree, so a
 *    plugin deployed by symlink or rsync can be running with them absent.
 * 2. **A MariaDB.** `Symfony\AI\Store\Bridge\MariaDb\Store` emits `VEC_DISTANCE_COSINE`,
 *    `VEC_FromText` and `VECTOR INDEX` — MariaDB's spelling. MySQL only gained a vector type in 9.0
 *    and named its functions differently (`STRING_TO_VECTOR`, `DISTANCE`), so no MySQL version runs
 *    this store. That is worth stating plainly because it is the opposite of the intuitive fix:
 *    upgrading MySQL does not help, changing engine does.
 *
 * The point of this class is that neither shortfall may reach a shopper. An unmet requirement makes
 * the feature **off** — the state `embeddingModel: ''` already means throughout this plugin — rather
 * than making the assistant throw.
 */
final class ShopInfoAvailabilityTest extends TestCase
{
    private function vectors(bool $available): VectorSupport
    {
        return new class($available) implements VectorSupport {
            public function __construct(
                private readonly bool $available,
            ) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function describe(): string
            {
                return $this->available ? 'MariaDB 11.8' : 'MySQL 8.0.46';
            }
        };
    }

    public function testAShopWithBothRequirementsCanRunShopInformation(): void
    {
        $availability = new ShopInfoAvailability($this->vectors(true), packagesInstalled: true);

        self::assertTrue($availability->isAvailable());
        self::assertNull($availability->unmetRequirement());
    }

    public function testAShopWithoutTheStorePackagesCannotRunIt(): void
    {
        $availability = new ShopInfoAvailability($this->vectors(true), packagesInstalled: false);

        self::assertFalse($availability->isAvailable());
    }

    public function testAShopWithoutAVectorCapableDatabaseCannotRunIt(): void
    {
        $availability = new ShopInfoAvailability($this->vectors(false), packagesInstalled: true);

        self::assertFalse($availability->isAvailable());
    }

    /**
     * A merchant reading "shop information is unavailable" needs to know which of the two it is,
     * because the fixes have nothing to do with each other: one is a composer install, the other is a
     * different database engine.
     */
    public function testTheMissingPackagesAreNamedInTheReason(): void
    {
        $reason = (new ShopInfoAvailability($this->vectors(true), packagesInstalled: false))->unmetRequirement();

        self::assertIsString($reason);
        self::assertStringContainsString('symfony/ai-store', $reason);
    }

    public function testTheDatabaseIsNamedInTheReasonAlongsideWhatWasFound(): void
    {
        $reason = (new ShopInfoAvailability($this->vectors(false), packagesInstalled: true))->unmetRequirement();

        self::assertIsString($reason);
        self::assertStringContainsString('MariaDB', $reason);
        // What the shop actually has, so the message is a diagnosis rather than a restatement.
        self::assertStringContainsString('MySQL 8.0.46', $reason);
    }

    /**
     * The packages are reported first when both are missing. They are the cheaper fix, and a merchant
     * told to change database engine when a composer install would have done is a merchant who stops
     * reading.
     */
    public function testThePackagesAreReportedFirstWhenNeitherRequirementIsMet(): void
    {
        $reason = (new ShopInfoAvailability($this->vectors(false), packagesInstalled: false))->unmetRequirement();

        self::assertIsString($reason);
        self::assertStringContainsString('symfony/ai-store', $reason);
    }
}
