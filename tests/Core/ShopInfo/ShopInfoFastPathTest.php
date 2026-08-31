<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;

/**
 * Whether the *indexed* store is in use — the other half of {@see ShopInfoAvailabilityTest}.
 *
 * These two questions used to be one, and merging them was the bug: a shop without MariaDB's vector
 * functions had shop information switched **off**, when what it actually needs is the portable store
 * that ranks in PHP. So `unmetRequirement()` now answers only "can the feature run at all", and
 * `fastPathUnmet()` answers "is the fast path in use, and if not, what did that cost".
 *
 * `fastPathUnmet()`'s sentence is not decoration. It is what the `retrieve.shopinfo.store` trace
 * event carries and what the admin screen shows, and spec D6 says the fallback must never be silent:
 * a MariaDB shop whose operator overlooked the suggested package would otherwise experience this as
 * "it got slow", with nothing anywhere saying why. Hence the assertions on what the sentence names.
 *
 * A separate class from `ShopInfoAvailabilityTest` because Mago caps a class at eleven methods and
 * because the seam is real — one class per question the production class now answers.
 */
final class ShopInfoFastPathTest extends TestCase
{
    private function availability(bool $vectorsWork, bool $nativeStoreInstalled): ShopInfoAvailability
    {
        $vectors = new class($vectorsWork) implements VectorSupport {
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

        return new ShopInfoAvailability($vectors, packagesInstalled: true, nativeStoreInstalled: $nativeStoreInstalled);
    }

    public function testTheIndexedStoreIsUsedWhenBothTheDatabaseAndThePackageAllowIt(): void
    {
        $availability = $this->availability(vectorsWork: true, nativeStoreInstalled: true);

        self::assertTrue($availability->nativeVectorStoreUsable());
        self::assertNull($availability->fastPathUnmet());
    }

    public function testAShopWithoutVectorFunctionsFallsBackAndNamesTheEngine(): void
    {
        $availability = $this->availability(vectorsWork: false, nativeStoreInstalled: true);

        self::assertFalse($availability->nativeVectorStoreUsable());

        $reason = $availability->fastPathUnmet();

        self::assertIsString($reason);
        self::assertStringContainsString('MariaDB', $reason);
        // What the shop actually runs, so the sentence diagnoses rather than restates.
        self::assertStringContainsString('MySQL 8.0.46', $reason);
    }

    /**
     * The case spec D6 was written for, and the one a MariaDB shop hits the moment
     * `symfony/ai-maria-db-store` becomes a suggestion instead of a requirement. The fix is one
     * command, so the sentence has to carry it.
     */
    public function testAShopWithoutThePackageFallsBackAndNamesTheComposerRequire(): void
    {
        $availability = $this->availability(vectorsWork: true, nativeStoreInstalled: false);

        self::assertFalse($availability->nativeVectorStoreUsable());

        $reason = $availability->fastPathUnmet();

        self::assertIsString($reason);
        self::assertStringContainsString('MariaDB', $reason);
        self::assertStringContainsString('composer require symfony/ai-maria-db-store', $reason);
    }

    /**
     * When the database cannot serve the indexed store, the package is beside the point: installing
     * it changes nothing on MySQL. Offering the `composer require` here would send an operator to
     * spend an afternoon on a command that cannot work — the inverse of `unmetRequirement()`, which
     * reports the *package* first because there the cheap fix is the real one.
     */
    public function testTheComposerRequireIsNotOfferedWhenTheDatabaseCouldNotUseItAnyway(): void
    {
        $availability = $this->availability(vectorsWork: false, nativeStoreInstalled: false);

        // Asserted in all four shapes, not only in the three where a sentence was obviously due:
        // `fastPathUnmet()` returning null while `nativeVectorStoreUsable()` is false would leave the
        // trace event and the merchant's screen both saying nothing was wrong.
        self::assertFalse($availability->nativeVectorStoreUsable());

        $reason = (string) $availability->fastPathUnmet();

        self::assertStringContainsString('MySQL 8.0.46', $reason);
        self::assertStringNotContainsString('composer require', $reason);
    }

    /**
     * The consequence the package sentence has to carry, and the reason it is not just "slower":
     * the two stores write to different tables and nothing copies between them, so the first
     * `composer update` that drops a merely *suggested* package leaves a MariaDB shop retrieving
     * from an empty table. Retrieval keeps working and finds nothing, which is the failure mode a
     * merchant is least able to diagnose.
     *
     * Only on this branch. A shop that never had a MariaDB never had passages in the other table,
     * and telling it to re-index would be noise.
     */
    public function testThePackageSentenceSaysTheDocumentsNeedIndexingAgain(): void
    {
        $missingPackage = (string) $this->availability(vectorsWork: true, nativeStoreInstalled: false)->fastPathUnmet();

        self::assertStringContainsString('index the documents again', $missingPackage);

        $missingDatabase = (string) $this->availability(
            vectorsWork: false,
            nativeStoreInstalled: true,
        )->fastPathUnmet();

        self::assertStringNotContainsString('index the documents', $missingDatabase);
    }

    /**
     * A merchant reading this needs to know whether to act, which means knowing what the fallback
     * actually does. "Slower" alone would send every shop with six passages chasing a database
     * migration it does not need.
     */
    public function testTheSentenceSaysWhatTheFallbackCosts(): void
    {
        $reason = (string) $this->availability(vectorsWork: false, nativeStoreInstalled: true)->fastPathUnmet();

        self::assertStringContainsString('PHP', $reason);
        self::assertStringContainsString('passages', $reason);
    }
}
