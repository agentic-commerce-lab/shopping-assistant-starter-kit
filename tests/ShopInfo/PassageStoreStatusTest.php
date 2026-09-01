<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;
use Swag\AssistantStarterKit\ShopInfo\AiStorePassageStore;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;
use Swag\AssistantStarterKit\ShopInfo\PassageStoreStatus;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoVectorTable;

/**
 * What the shop-information screen tells a merchant about the store underneath it.
 *
 * **The half of spec D6 the trace event does not cover.** `retrieve.shopinfo.store` makes the choice
 * findable to whoever debugs a turn; nothing made it findable to the merchant whose shop got slower.
 * The case this exists for is concrete: `symfony/ai-maria-db-store` is only suggested, so the first
 * `composer update` on a MariaDB shop can drop it. The chooser then hands out the portable store,
 * which reads a table the MariaDB store never wrote to — so retrieval returns nothing found, and
 * from the outside that looks like the documents were lost rather than like a package went missing.
 */
final class PassageStoreStatusTest extends TestCase
{
    private function availability(bool $vectorsWork, bool $packageInstalled = true): ShopInfoAvailability
    {
        $vectors = new class($vectorsWork) implements VectorSupport {
            public function __construct(
                private readonly bool $works,
            ) {}

            public function isAvailable(): bool
            {
                return $this->works;
            }

            public function describe(): string
            {
                return $this->works ? 'MariaDB 11.8' : 'MySQL 8.0.46';
            }
        };

        return new ShopInfoAvailability($vectors, packagesInstalled: true, nativeStoreInstalled: $packageInstalled);
    }

    /** @param int|false $portableWidth @param int|false $vectorWidth */
    private function statusFor(
        bool $vectorsWork,
        bool $packageInstalled = true,
        int|false $portableWidth = false,
        int|false $vectorWidth = false,
    ): PassageStoreStatus {
        $portableConnection = $this->createMock(Connection::class);
        $portableConnection->method('fetchOne')->willReturn($portableWidth);

        $vectorConnection = $this->createMock(Connection::class);
        $vectorConnection->method('fetchOne')->willReturn($vectorWidth === false ? false : "vector($vectorWidth)");

        return new PassageStoreStatus(
            $this->availability($vectorsWork, $packageInstalled),
            new AiStorePassageStore(new ShopInfoVectorTable($vectorConnection)),
            new DalPortablePassageStore($portableConnection),
        );
    }

    public function testAShopWithVectorFunctionsAndThePackageReportsTheIndexedStore(): void
    {
        self::assertSame('mariadb', $this->statusFor(vectorsWork: true)->describe()['store']);
    }

    public function testAShopWithoutVectorFunctionsReportsThePortableStore(): void
    {
        self::assertSame('portable', $this->statusFor(vectorsWork: false)->describe()['store']);
    }

    /**
     * The fast path is a preference, not a requirement, so its absence is explained rather than
     * merely reported. A merchant reading "portable" with no reason cannot act on it.
     */
    public function testTheFallbackCarriesTheReasonTheMerchantCanActOn(): void
    {
        $reason = $this->statusFor(vectorsWork: false)->describe()['reason'];

        self::assertIsString($reason);
        self::assertStringContainsString('MariaDB', $reason);
    }

    public function testTheFastPathCarriesNoReasonBecauseNothingIsWrong(): void
    {
        self::assertNull($this->statusFor(vectorsWork: true)->describe()['reason']);
    }

    /**
     * The `composer update` case, and the only one where a merchant must be told to act: the shop can
     * still run the indexed store, the package went missing, and everything indexed with it is sitting
     * in a table the portable store cannot read.
     */
    public function testItWarnsWhenTheStoreNowInUseIsNotTheOneHoldingThePassages(): void
    {
        $status = $this->statusFor(vectorsWork: true, packageInstalled: false, vectorWidth: 1024);

        self::assertSame('portable', $status->describe()['store']);
        self::assertTrue($status->describe()['otherStoreHasPassages']);
    }

    public function testNoWarningWhenTheOtherStoreIsEmpty(): void
    {
        $status = $this->statusFor(vectorsWork: true, packageInstalled: false);

        self::assertFalse($status->describe()['otherStoreHasPassages']);
    }

    /**
     * The mirror case: running the indexed store while passages sit in the portable table, which is
     * what a shop looks like after the package is put back.
     */
    public function testItWarnsInTheOtherDirectionToo(): void
    {
        $status = $this->statusFor(vectorsWork: true, portableWidth: 1024);

        self::assertSame('mariadb', $status->describe()['store']);
        self::assertTrue($status->describe()['otherStoreHasPassages']);
    }

    /**
     * Spec requirement 5, applied to a screen rather than a shopper: a store that cannot be asked
     * must not take the page down with it. A shop whose vendor tree lost the bridge entirely would
     * otherwise turn one unreadable status row into an admin screen that renders nothing.
     */
    public function testAStoreThatThrowsIsReportedAsEmptyRatherThanFatal(): void
    {
        $throwing = $this->createMock(Connection::class);
        $throwing->method('fetchOne')->willThrowException(new \RuntimeException('no such table'));

        $status = new PassageStoreStatus(
            $this->availability(true),
            new AiStorePassageStore(new ShopInfoVectorTable($throwing)),
            new DalPortablePassageStore($throwing),
        );

        self::assertFalse($status->describe()['otherStoreHasPassages']);
    }
}
