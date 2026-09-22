<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\PluginLifecycle;

use Doctrine\DBAL\Connection;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoVectorTable;

/**
 * Drops everything this plugin created, when a merchant uninstalls it and declines to keep the data.
 *
 * **The decision every migration deferred to.** Each `updateDestructive()` in `src/Migration` refuses
 * to drop these tables and says why: *"removal is a deliberate uninstall decision and not a migration
 * side effect."* That reasoning is right — `updateDestructive()` runs on plugin *update*, where
 * dropping a merchant's trace history would be indefensible. But the decision it points at had never
 * been written, so uninstalling removed nothing at all.
 *
 * Read out of `vendor/shopware/core/Framework/Plugin/PluginLifecycleService.php`: with
 * `keepUserData()` false, Shopware removes the migration records, the plugin's `system_config` and
 * its assets, then calls `Plugin::uninstall()` — commented in its own source as *"plugin->uninstall()
 * will remove the tables etc of the plugin"*. Ours did not exist, so
 * `swag_assistant_conversation.transcript` — everything shoppers had typed — survived an uninstall
 * performed specifically to remove it.
 *
 * **A separate class rather than a body on the plugin.** `SwagAssistantStarterKit` is instantiated by
 * Shopware with a container this project cannot build in a test, and the thing worth testing here is
 * the list and its order, not the lifecycle plumbing.
 */
final class AssistantTableRemoval
{
    /**
     * Every table this plugin creates, children before parents.
     *
     * **Order is load-bearing.** `swag_assistant_trace_event` carries a foreign key to
     * `swag_assistant_conversation`; dropping the parent first fails on any shop that has rows,
     * which is every shop where this matters. `swag_assistant_insight_finding` carries two —
     * `conversation_id` and `run_id` — so it has to precede `swag_assistant_conversation` **and**
     * `swag_assistant_insight_run`. Those two tables arrived with the nightly insights and were
     * missing from this list, which did not merely leave findings behind: the foreign key made the
     * whole uninstall fail at `swag_assistant_conversation` with SQLSTATE 23000, after
     * `swag_assistant_trace_event` had already gone. A merchant got a half-dropped schema and an
     * exception.
     *
     * **It does not take a single finding to trigger.** InnoDB refuses to drop a table another
     * existing table references, rows or no rows — probed directly on MariaDB with a two-table
     * replica of this shape and an empty child: `ERROR 1451 (23000)`, the same errno the real
     * uninstall raised. So every shop that ever applied the insights migration was affected,
     * including one that never switched insights on.
     *
     * `ShopInfoVectorTable::TABLE` is referenced by its constant rather than typed out, because it is
     * the one table no migration creates — it is built lazily at the first write, at the width the
     * configured embedding model produces. Anyone assembling this list from `src/Migration` alone
     * would leave it behind, full of the shop's document text.
     *
     * @var list<string>
     */
    public const TABLES = [
        'swag_assistant_trace_event',
        'swag_assistant_insight_finding',
        'swag_assistant_insight_run',
        'swag_assistant_conversation',
        ShopInfoVectorTable::TABLE,
        'swag_assistant_shop_info_passage',
        'swag_assistant_document',
    ];

    private function __construct() {}

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately. An uninstall that cannot drop a table
     *     must say so: swallowing it would report a clean removal over data still sitting in the
     *     database, which is the failure this class exists to end rather than to relocate.
     */
    public static function drop(Connection $connection): void
    {
        foreach (self::TABLES as $table) {
            // IF EXISTS, so uninstalling after a merchant already removed a table by hand does not
            // fail halfway and leave the rest standing.
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
}
