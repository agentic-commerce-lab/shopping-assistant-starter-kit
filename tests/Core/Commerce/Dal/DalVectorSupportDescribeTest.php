<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalVectorSupport;

/**
 * What the shop runs, said so a merchant cannot misread it.
 *
 * **The sentence this feeds names two databases.** `ShopInfoAvailability::fastPathUnmet()` reads
 * "the indexed MariaDB store needs MariaDB 11.7 or newer … and this shop runs X". While X was the
 * bare output of `SELECT VERSION()`, a MySQL shop rendered that as "… needs MariaDB 11.7 or newer …
 * and this shop runs 8.0.46", and the obvious reading is that the shop is on an OLD MariaDB. That
 * points a merchant at a MariaDB upgrade — the one action the last sentence of that message exists
 * to rule out. Reported by a reader who made exactly that inference.
 *
 * The asymmetry is in the engines, not in us: MariaDB puts its name in `VERSION()`, MySQL does not
 * and only identifies itself in `@@version_comment`.
 */
final class DalVectorSupportDescribeTest extends TestCase
{
    private function describing(string $version, string $comment): DalVectorSupport
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['version' => $version, 'comment' => $comment]);

        return new DalVectorSupport($connection);
    }

    public function testMySqlIsNamedBecauseItsVersionStringNeverNamesItself(): void
    {
        self::assertSame('MySQL 8.0.46', $this->describing('8.0.46', 'MySQL Community Server - GPL')->describe());
    }

    /**
     * The build suffix goes. `11.8.8-MariaDB-ubu2404` is what the server answers and is not a version
     * a merchant would recognise from anywhere else — least of all from the "11.7 or newer" the same
     * sentence asks them to compare it against.
     */
    public function testMariaDbIsNamedOnceAndWithoutItsBuildSuffix(): void
    {
        self::assertSame(
            'MariaDB 11.8.8',
            $this->describing('11.8.8-MariaDB-ubu2404', 'mariadb.org binary distribution')->describe(),
        );
    }

    /**
     * A fork that says MariaDB only in the comment is still MariaDB, and one that says neither is
     * treated as MySQL — every engine in that group is MySQL-compatible and equally unable to serve
     * the indexed store, which is the only decision this label informs.
     */
    public function testTheCommentIdentifiesTheEngineWhenTheVersionDoesNot(): void
    {
        self::assertSame(
            'MariaDB 10.11.2',
            $this->describing('10.11.2', 'mariadb.org binary distribution')->describe(),
        );
    }

    public function testAnUnrecognisedEngineIsReportedAsMySqlCompatibleRatherThanGuessedAt(): void
    {
        self::assertSame('MySQL 8.0.36-28', $this->describing('8.0.36-28', 'Percona Server')->describe());
    }

    public function testADatabaseThatWillNotAnswerIsSaidToBeUnknownRatherThanBlank(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('gone'));

        self::assertSame('an unknown database', (new DalVectorSupport($connection))->describe());
    }

    public function testTheVersionIsAskedForOnlyOnce(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['version' => '8.0.46', 'comment' => 'MySQL Community Server - GPL']);

        $support = new DalVectorSupport($connection);
        $support->describe();
        $support->describe();
    }
}
