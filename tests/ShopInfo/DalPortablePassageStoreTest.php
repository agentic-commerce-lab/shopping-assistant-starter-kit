<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;

/**
 * The store for shops without MariaDB's vector support — the write path and the small facts
 * (`dimension()`, `deleteDocument()`) that do not need a stored row to answer.
 *
 * It ranks in PHP because it has to: MySQL Community has no distance function at any version —
 * `DISTANCE()` is HeatWave-only — so similarity cannot be computed in SQL. The corpus this serves is
 * a shop's legal pages and uploads, so a scan is not a compromise; it is also the arithmetic the eval
 * suite has always measured, since `ShopInfoFixture` uses `InMemoryPassageStore`.
 *
 * `query()`'s own tests live in {@see DalPortablePassageStoreQueryTest}: kept apart because this
 * repo's quality gate caps methods per class, and the read path already earns its own file on
 * substance — ranking, the channel filter (R12), and the corrupt-row skip are all read-side concerns.
 */
final class DalPortablePassageStoreTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItStoresOneRowPerPassage(): void
    {
        /** @var list<string> $inserted */
        $inserted = [];
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$inserted): int {
                $inserted[] = $sql;

                return 1;
            });

        (new DalPortablePassageStore($connection))->add(
            [
                new ShopInfoPassage('doc-1', 'terms.txt', 'Scope', 'first'),
                new ShopInfoPassage('doc-1', 'terms.txt', 'Returns', 'second'),
            ],
            [[1.0, 0.0], [0.0, 1.0]],
            self::CHANNEL,
        );

        self::assertCount(2, $inserted);
    }

    public function testMismatchedCountsAreRefusedBeforeAnythingIsWritten(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);

        (new DalPortablePassageStore($connection))->add(
            [new ShopInfoPassage('doc-1', 'terms.txt', 'Scope', 'first')],
            [[1.0, 0.0], [0.0, 1.0]],
            self::CHANNEL,
        );
    }

    /**
     * R2: the width guard the interface promises. A store that already holds 2-wide vectors must
     * refuse a batch of a different width before writing anything — the same invariant
     * `InMemoryPassageStore::add()` enforces via `StoreWidth::guard()`.
     */
    public function testAddRefusesASecondBatchAtADifferentWidth(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(2);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);

        (new DalPortablePassageStore($connection))->add(
            [new ShopInfoPassage('doc-1', 'terms.txt', 'Scope', 'first')],
            [[1.0, 0.0, 0.0]],
            self::CHANNEL,
        );
    }

    public function testDeletingADocumentRemovesItsRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(2);

        (new DalPortablePassageStore($connection))->deleteDocument('doc-1');
    }

    public function testDimensionIsNullOnAnEmptyStore(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        self::assertNull((new DalPortablePassageStore($connection))->dimension());
    }

    public function testDimensionIsTheStoredWidth(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1024);

        self::assertSame(1024, (new DalPortablePassageStore($connection))->dimension());
    }
}
