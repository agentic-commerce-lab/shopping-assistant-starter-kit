<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `swag_assistant_trace_event.elapsed_ms`.
 *
 * A second migration rather than an edit to {@see Migration1755720000CreateAssistantTables}: the
 * tables exist in installed shops, and a migration that has already run is never re-run.
 *
 * `NOT NULL DEFAULT 0` so rows written before this column existed read as 0 rather than NULL. The
 * Administration renders a 0 offset as "—", never as "0ms": a row that predates the column has no
 * offset rather than an offset of zero, and that distinction is the whole of ruling R62.
 */
class Migration1787270400AddTraceEventElapsedMs extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787270400;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, for the same reason as
     *         {@see Migration1755720000CreateAssistantTables}: a schema change that cannot apply
     *         must fail loudly rather than leave a plugin that boots and then errors when the first
     *         trace is written.
     */
    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `swag_assistant_trace_event` LIKE :column', [
            'column' => 'elapsed_ms',
        ]);

        if ($existing !== []) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `swag_assistant_trace_event` ADD `elapsed_ms` INT(11) NOT NULL DEFAULT 0 AFTER `payload`',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the column is additive and carries no data anyone can lose.
    }
}
