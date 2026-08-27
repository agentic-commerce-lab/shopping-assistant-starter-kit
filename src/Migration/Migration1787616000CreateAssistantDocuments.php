<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates `swag_assistant_document` — the uploaded shop documents (spec R9).
 *
 * **Only this table.** The vector store's own table is deliberately not created here: a `VECTOR`
 * column has a fixed width, the width comes from whichever embedding model the merchant configured,
 * and at install time that is usually none. A migration would have to guess, and a shop on a
 * 3072-wide model would get insert failures from a table created before anyone knew what it was for.
 * See `ShopInfoVectorTable`, which creates it on the first write at the width actually in use.
 *
 * **`UNIQUE (sales_channel_id, name)`** is what makes spec R8 enforceable at the bottom rather than
 * only in application code: the id is derived from exactly that pair, so re-uploading a file name
 * updates one row instead of accumulating versions the merchant cannot see. Per channel, because two
 * channels genuinely have different terms (R12) and the same file name in each is two documents.
 *
 * `status` is indexed with the channel because the admin list in Part 2 reads one channel's documents
 * and the failed ones are the reason anyone opens it.
 */
class Migration1787616000CreateAssistantDocuments extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787616000;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in
     *         {@see Migration1755720000CreateAssistantTables}: a schema change that cannot apply must
     *         fail loudly rather than leave a plugin that boots and then errors on the first upload
     */
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_assistant_document` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `extension` VARCHAR(32) NOT NULL DEFAULT '',
                `sales_channel_id` VARCHAR(32) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `status_reason` LONGTEXT NULL,
                `chunk_count` INT(11) NOT NULL DEFAULT 0,
                `dimension` INT(11) NOT NULL DEFAULT 0,
                `text` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.swag_assistant_document.channel_name` (`sales_channel_id`, `name`),
                KEY `idx.swag_assistant_document.channel_status` (`sales_channel_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. Dropping this table would silently orphan every passage in the vector
        // store: the passages carry the document id as metadata with no constraint behind it, so the
        // list would be gone while retrieval kept answering from documents nobody could see.
    }
}
