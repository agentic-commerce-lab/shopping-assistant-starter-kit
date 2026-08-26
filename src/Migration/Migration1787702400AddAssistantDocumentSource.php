<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `swag_assistant_document.source`.
 *
 * A second migration rather than an edit to {@see Migration1787616000CreateAssistantDocuments}: the
 * table exists in shops that already indexed documents, and a migration that has run is never re-run.
 *
 * **`DEFAULT 'upload'` is what makes the existing rows correct.** Every document written before this
 * column existed came from a file a merchant chose, so `upload` is not a fallback here — it is the
 * true value, and re-indexing one of those rows must keep reading from its stored text rather than
 * trying to find a CMS page it never had.
 */
class Migration1787702400AddAssistantDocumentSource extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787702400;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in the migration that created this
     *         table: a schema change that cannot apply must fail loudly rather than leave a plugin
     *         that boots and then errors on the first upload
     */
    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `swag_assistant_document` LIKE :column', [
            'column' => 'source',
        ]);

        if ($existing !== []) {
            return;
        }

        $connection->executeStatement(
            "ALTER TABLE `swag_assistant_document`
             ADD `source` VARCHAR(32) NOT NULL DEFAULT 'upload' AFTER `extension`",
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the column is additive and every existing row's value is correct.
    }
}
