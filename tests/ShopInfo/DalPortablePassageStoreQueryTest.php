<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;

/**
 * `DalPortablePassageStore::query()` — the read path: ranking, the per-channel filter (spec R12),
 * the width guard on the query vector, and surviving a corrupt row.
 *
 * Split out of {@see DalPortablePassageStoreTest} because this repo's quality gate caps methods per
 * class, and the read path already has enough on its own to earn the file: it is the half of the
 * store where a dropped `WHERE` clause serves one shop's legal terms to another, and where a bad
 * byte in one row must not cost the shopper every other passage.
 */
final class DalPortablePassageStoreQueryTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItRanksTheRowsItReadAndHonoursTheThreshold(): void
    {
        $connection = $this->createMock(Connection::class);
        // dimension() is consulted first (R2's width guard), so it must answer with the width the
        // stored rows actually carry — 2 — or the query would be refused before it ever reads a row.
        $connection->method('fetchOne')->willReturn(2);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'document_id' => 'doc-1',
                    'document_name' => 'terms.txt',
                    'section' => 'Far',
                    'text' => 'far',
                    'vector' => '[0,1]',
                ],
                [
                    'document_id' => 'doc-1',
                    'document_name' => 'terms.txt',
                    'section' => 'Near',
                    'text' => 'near',
                    'vector' => '[1,0]',
                ],
            ]);

        $found = (new DalPortablePassageStore($connection))->query([1.0, 0.0], self::CHANNEL, 0.5, 10);

        self::assertCount(1, $found);
        $best = $found[0] ?? null;
        self::assertNotNull($best);
        self::assertSame('Near', $best->section);
    }

    /**
     * A row whose stored vector will not decode is skipped rather than fatal: one corrupt row must
     * not cost the shopper every other passage in the shop. Two distinct failure shapes are covered
     * on purpose — a `vector` column holding text that is not JSON at all, and one whose JSON is
     * valid but decodes to a scalar rather than an array — because `query()` guards against both
     * (`json_decode` failing outright, and `\is_array($stored)` catching the scalar case). The point
     * of the assertion is not merely "it doesn't throw"; it's that the good row still comes back.
     */
    public function testACorruptVectorIsSkippedAndItsNeighboursStillAnswer(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(2);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'document_id' => 'doc-1',
                    'document_name' => 'terms.txt',
                    'section' => 'NotJson',
                    'text' => 'not json',
                    'vector' => 'not json',
                ],
                [
                    'document_id' => 'doc-1',
                    'document_name' => 'terms.txt',
                    'section' => 'ScalarJson',
                    'text' => 'scalar json',
                    'vector' => '42',
                ],
                [
                    'document_id' => 'doc-1',
                    'document_name' => 'terms.txt',
                    'section' => 'Good',
                    'text' => 'good',
                    'vector' => '[1,0]',
                ],
            ]);

        $found = (new DalPortablePassageStore($connection))->query([1.0, 0.0], self::CHANNEL, 0.5, 10);

        self::assertCount(1, $found);
        $best = $found[0] ?? null;
        self::assertNotNull($best);
        self::assertSame('Good', $best->section);
    }

    /**
     * R12: two sales channels genuinely have different legal terms, so a query without the filter
     * serves one shop's revocation notice in another. Both halves are asserted, deliberately: the
     * bound-parameter check alone cannot fail if a regression keeps `'channel' => $salesChannelId`
     * in the params array but deletes the `WHERE` clause from the SQL — the id would still "arrive"
     * with nothing to filter by. Asserting the SQL text pins the clause itself; asserting the params
     * pins that the id reaches it as a bound parameter and never as string interpolation, which
     * would make a sales-channel id able to become SQL.
     */
    public function testTheQueryFiltersBySalesChannel(): void
    {
        $sqlSeen = null;
        $params = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(2);
        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $given) use (&$sqlSeen, &$params): array {
                $sqlSeen = $sql;
                $params = $given;

                return [];
            });

        (new DalPortablePassageStore($connection))->query([1.0, 0.0], self::CHANNEL, 0.4, 3);

        self::assertIsString($sqlSeen);
        self::assertStringContainsString('WHERE `sales_channel_id` = :channel', $sqlSeen);
        self::assertContains(self::CHANNEL, $params);
    }

    /**
     * R2: mirrors `AiStorePassageStore::query()` — nothing indexed is not an error, so an empty store
     * answers with no results rather than a width complaint about a comparison that cannot happen.
     */
    public function testQueryOnAnEmptyStoreReturnsNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $found = (new DalPortablePassageStore($connection))->query([1.0, 0.0], self::CHANNEL, 0.5, 10);

        self::assertSame([], $found);
    }

    /**
     * R2: the same guard on the read side. A query vector of the wrong width must be refused with
     * the exact message text `AiStorePassageStore::query()` uses, so a merchant reading a log cannot
     * tell which store produced it.
     */
    public function testQueryRefusesAVectorOfTheWrongWidth(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(2);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'The shop information store holds 2-wide vectors but the question embedded to 3. '
            . 'The embedding model changed since indexing: index the documents again.',
        );

        (new DalPortablePassageStore($connection))->query([1.0, 0.0, 0.0], self::CHANNEL, 0.5, 10);
    }
}
