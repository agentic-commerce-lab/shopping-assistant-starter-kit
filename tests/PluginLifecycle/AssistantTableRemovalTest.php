<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\PluginLifecycle;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\PluginLifecycle\AssistantTableRemoval;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoVectorTable;

/**
 * What uninstalling takes with it.
 *
 * **The gap this closes.** Read out of
 * `vendor/shopware/core/Framework/Plugin/PluginLifecycleService.php`: with `keepUserData()` false,
 * Shopware removes the migration records, the plugin's `system_config` entries and its assets, and
 * then calls `Plugin::uninstall()` — its own source comment says *"plugin->uninstall() will remove
 * the tables etc of the plugin"*. `SwagAssistantStarterKit` was `class SwagAssistantStarterKit
 * extends Plugin {}`, so nothing was ever dropped: `swag_assistant_conversation` and its
 * `transcript` column — everything shoppers had typed — survived an uninstall a merchant performed
 * specifically to remove it.
 *
 * The intent had been written down and only half implemented. Every migration's
 * `updateDestructive()` declines to drop these tables and says why: *"removal is a deliberate
 * uninstall decision and not a migration side effect."* This is that decision.
 */
final class AssistantTableRemovalTest extends TestCase
{
    /**
     * @param list<string> $statements
     */
    private function connectionRecording(array &$statements): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            });

        return $connection;
    }

    public function testItDropsEveryTableThePluginCreated(): void
    {
        $statements = [];

        AssistantTableRemoval::drop($this->connectionRecording($statements));

        foreach (AssistantTableRemoval::TABLES as $table) {
            self::assertNotEmpty(
                array_filter($statements, static fn(string $sql): bool => str_contains($sql, $table)),
                $table . ' was not dropped',
            );
        }
    }

    /**
     * **The one no migration knows about.** `swag_assistant_shop_info_vector` is created lazily at the
     * first write, at the width the configured embedding model produces, precisely because a `VECTOR`
     * column's width is fixed at creation. Anyone assembling this list from the migration files alone
     * would leave it behind — so it is asserted by the constant the table itself is named by, not by a
     * string typed here.
     */
    public function testItDropsTheLazilyCreatedVectorTableToo(): void
    {
        self::assertContains(ShopInfoVectorTable::TABLE, AssistantTableRemoval::TABLES);
    }

    /**
     * `swag_assistant_trace_event` carries a foreign key to `swag_assistant_conversation`. Dropping
     * the parent first fails on a shop with data in it — which is every shop that would notice.
     */
    public function testTheChildTableIsDroppedBeforeItsParent(): void
    {
        $order = array_values(AssistantTableRemoval::TABLES);

        self::assertLessThan(
            array_search('swag_assistant_conversation', $order, true),
            array_search('swag_assistant_trace_event', $order, true),
        );
    }

    /**
     * `IF EXISTS`, because uninstalling a plugin whose tables a merchant already removed by hand must
     * not fail halfway and leave the rest standing.
     */
    public function testEveryDropToleratesAnAlreadyMissingTable(): void
    {
        $statements = [];

        AssistantTableRemoval::drop($this->connectionRecording($statements));

        foreach ($statements as $sql) {
            self::assertStringContainsString('IF EXISTS', $sql);
        }
    }

    /**
     * Nothing outside this plugin. Shopware's own tables are obviously off limits, but so is the
     * catalogue: products and categories a seeder wrote are ordinary shop data, and a merchant who
     * uninstalls an assistant is not asking for their products to be deleted.
     */
    public function testItTouchesNothingOutsideThePluginsOwnTables(): void
    {
        foreach (AssistantTableRemoval::TABLES as $table) {
            self::assertStringStartsWith('swag_assistant_', $table);
        }
    }
}
