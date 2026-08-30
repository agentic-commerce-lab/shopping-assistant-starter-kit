<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Migration\Migration1788307200AddConversationScope;

/**
 * `scope_type` lets a row be compared against the scope a shopper was presented with.
 *
 * The backfill is the part worth pinning: it must promote a row to `customer` when it has a
 * `customer_id`, and it must **not** need to write `guest` anywhere — the `ALTER`'s
 * `DEFAULT 'guest'` already leaves every other existing row, including one whose customer has
 * since been deleted, reading as a guest's. See the migration's docblock for why that ambiguity
 * is accepted.
 */
final class AddConversationScopeTest extends TestCase
{
    public function testItReportsItsCreationTimestamp(): void
    {
        self::assertSame(1788307200, (new Migration1788307200AddConversationScope())->getCreationTimestamp());
    }

    public function testItSkipsEverythingWhenTheColumnAlreadyExists(): void
    {
        self::assertSame([], $this->updateWithColumnAlreadyPresent());
    }

    public function testItAddsAllThreeColumnsWhenTheColumnIsMissing(): void
    {
        $statements = $this->updateWithColumnMissing();

        self::assertNotSame([], $statements);
        $alter = $statements[0];
        assert(\is_string($alter));

        self::assertStringContainsString('ALTER TABLE `swag_assistant_conversation`', $alter);
        self::assertStringContainsString("ADD `scope_type` VARCHAR(16) NOT NULL DEFAULT 'guest'", $alter);
        self::assertStringContainsString('ADD `commercial_employee_id` BINARY(16) NULL', $alter);
        self::assertStringContainsString('ADD `commercial_organisation_id` BINARY(16) NULL', $alter);

        // No foreign keys on the two commercial columns: they would reference tables that belong
        // to Shopware Commercial, an optional extension that may not be installed.
        self::assertStringNotContainsString('FOREIGN KEY', $alter);
    }

    public function testTheBackfillRunsAfterTheAlterAndOnlyEverPromotesToCustomer(): void
    {
        $statements = $this->updateWithColumnMissing();

        self::assertCount(2, $statements, 'expected exactly the ALTER and the backfill UPDATE');

        $alter = $statements[0];
        assert(\is_string($alter));
        self::assertStringContainsString('ALTER TABLE', $alter);

        $backfill = $statements[1];
        assert(\is_string($backfill));
        self::assertStringContainsString("SET `scope_type` = 'customer'", $backfill);
        self::assertStringContainsString('WHERE `customer_id` IS NOT NULL', $backfill);
        self::assertStringNotContainsString("'guest'", $backfill);
    }

    public function testUpdateDestructiveDoesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('fetchFirstColumn');

        (new Migration1788307200AddConversationScope())->updateDestructive($connection);
    }

    /** @return list<string> the SQL of every executeStatement call, in call order */
    private function updateWithColumnAlreadyPresent(): array
    {
        return $this->update(['scope_type']);
    }

    /** @return list<string> the SQL of every executeStatement call, in call order */
    private function updateWithColumnMissing(): array
    {
        return $this->update([]);
    }

    /**
     * @param list<string>  $existingColumn what `SHOW COLUMNS ... LIKE 'scope_type'` reports
     * @return list<string> the SQL of every executeStatement call, in call order
     */
    private function update(array $existingColumn): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn($existingColumn);

        $statements = [];
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                /**
                 * @param array<string, mixed> $params
                 */
                static function (string $sql, array $params = []) use (&$statements): int {
                    $statements[] = $sql;

                    return 1;
                },
            );

        (new Migration1788307200AddConversationScope())->update($connection);

        return $statements;
    }
}
