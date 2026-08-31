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
 * `vendor/` had never installed `symfony/ai-store`. The whole assistant was down for a feature nobody
 * was using.
 *
 * That is the one requirement left here: `symfony/ai-store` carries `Vectorizer`, which
 * `PlatformEmbedder` needs to turn text into a vector at all, and Shopware autoloads a plugin's
 * dependencies from the SHOP's vendor tree — so a plugin deployed by symlink or rsync can be running
 * with it absent.
 *
 * **The database is no longer one of them.** It used to be: the only passage store this plugin
 * shipped emitted MariaDB's `VEC_DISTANCE_COSINE`, so a MySQL shop had the feature switched off.
 * `DalPortablePassageStore` ranks in PHP and runs anywhere Shopware does, so the question moved to
 * {@see ShopInfoFastPathTest} — where it decides which store answers, not whether any does.
 *
 * The point of the class is unchanged: a shortfall may not reach a shopper. It makes the feature
 * **off** — the state `embeddingModel: ''` already means throughout this plugin — rather than making
 * the assistant throw.
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

    public function testAShopWithTheStorePackageCanRunShopInformation(): void
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

    /**
     * The inversion this plan exists for. A shop whose database has no vector functions used to read
     * back as a shop with the feature switched off; it now runs it over the portable store. If this
     * ever asserts `false` again, `DalPortablePassageStore` is dead code and nothing else in the
     * suite would say so.
     */
    public function testAShopWithoutAVectorCapableDatabaseStillRunsIt(): void
    {
        $availability = new ShopInfoAvailability($this->vectors(false), packagesInstalled: true);

        self::assertTrue($availability->isAvailable());
        self::assertNull($availability->unmetRequirement());
    }

    /**
     * A merchant reading "shop information is unavailable" needs the package named, because that is
     * the whole fix: one `composer require` in the shop, not in the plugin.
     */
    public function testTheMissingPackageIsNamedInTheReason(): void
    {
        $reason = (new ShopInfoAvailability($this->vectors(true), packagesInstalled: false))->unmetRequirement();

        self::assertIsString($reason);
        self::assertStringContainsString('symfony/ai-store', $reason);
    }

    /**
     * The database is never the reason the feature is off, not even when it *also* cannot run the
     * indexed store. Naming it here would send an operator to change engine when the assistant needs
     * a package — and the engine question has its own sentence, in `fastPathUnmet()`.
     */
    public function testTheDatabaseIsNotBlamedWhenThePackageIsTheThingMissing(): void
    {
        $reason = (string) (new ShopInfoAvailability(
            $this->vectors(false),
            packagesInstalled: false,
        ))->unmetRequirement();

        self::assertStringContainsString('symfony/ai-store', $reason);
        self::assertStringNotContainsString('MySQL', $reason);
    }
}
