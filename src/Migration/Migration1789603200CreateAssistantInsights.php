<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates `swag_assistant_insight_run` and `swag_assistant_insight_finding` (spec D23).
 *
 * **Two tables rather than one, and the split is the design.** A run row holds counts and a window;
 * it names nobody and is never pruned, which is what makes a trend chart over months possible. A
 * finding row quotes a conversation, so it is pruned with that conversation. `search_terms` sits on
 * the run row but is shopper-authored, so it is emptied on prune while `metrics` stays — one JSON
 * column holding both could not be pruned by half.
 *
 * `conversation_id` is nullable with `ON DELETE SET NULL` rather than `CASCADE`: the pruner deletes
 * findings explicitly, and a row that survives a delete order we did not anticipate must read as a
 * finding with a dead link rather than vanish silently.
 *
 * `created_at`/`updated_at` on both tables because `EntityDefinition::defaultFields()` appends them
 * to every definition whether or not anything updates the row; `dal:validate` is what says so, and
 * it is worth running after this migration for exactly that reason.
 */
class Migration1789603200CreateAssistantInsights extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789603200;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in
     *         {@see Migration1755720000CreateAssistantTables}: a schema change that cannot apply
     *         must fail loudly rather than leave a plugin that boots and then errors on the first
     *         nightly run
     */
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `swag_assistant_insight_run` (
                    `id` BINARY(16) NOT NULL,
                    `window_start` DATETIME(3) NOT NULL,
                    `window_end` DATETIME(3) NOT NULL,
                    `metrics` JSON NOT NULL,
                    `search_terms` JSON NULL,
                    `sample_seed` VARCHAR(64) NOT NULL DEFAULT '',
                    `judge_error` VARCHAR(255) NULL,
                    `created_at` DATETIME(3) NOT NULL,
                    `updated_at` DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.insight_run.window_end` (`window_end`)
                ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `swag_assistant_insight_finding` (
                    `id` BINARY(16) NOT NULL,
                    `run_id` BINARY(16) NOT NULL,
                    `conversation_id` BINARY(16) NULL,
                    `type` VARCHAR(64) NOT NULL,
                    `severity` VARCHAR(32) NOT NULL DEFAULT 'info',
                    `summary` LONGTEXT NOT NULL,
                    `quote` LONGTEXT NULL,
                    `suggestion` LONGTEXT NULL,
                    `created_at` DATETIME(3) NOT NULL,
                    `updated_at` DATETIME(3) NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx.insight_finding.run` (`run_id`),
                    KEY `idx.insight_finding.conversation` (`conversation_id`),
                    CONSTRAINT `fk.insight_finding.run_id` FOREIGN KEY (`run_id`)
                        REFERENCES `swag_assistant_insight_run` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk.insight_finding.conversation_id` FOREIGN KEY (`conversation_id`)
                        REFERENCES `swag_assistant_conversation` (`id`) ON DELETE SET NULL
                ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. Both tables are dropped by the plugin's uninstall path, which
        // already handles the assistant's own tables.
    }
}
