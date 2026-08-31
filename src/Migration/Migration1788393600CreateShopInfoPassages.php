<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates `swag_assistant_shop_info_passage` — where the portable store keeps passages on a database
 * without MariaDB's vector support.
 *
 * **An ordinary migration, unlike the vector table.** `ShopInfoVectorTable` creates its own table
 * lazily because a `VECTOR(n)` column's width is fixed at creation and comes from whichever embedding
 * model the merchant configured, which at install time is usually none. `vector` here is `JSON` and
 * has no width, so there is nothing to guess — which also means Doctrine can describe this table and
 * uninstall can find it without anybody remembering it exists.
 *
 * `dimension` is stored alongside so `dimension()` can answer without decoding a vector, and so a
 * width mismatch after an embedding-model change is caught by the same `StoreWidth` message the
 * MariaDB store produces.
 *
 * `document_name` is denormalised onto the passage rather than joined from `swag_assistant_document`.
 * `ShopInfoPassage` carries it, the MariaDB store keeps it in the vector row's metadata for the same
 * reason, and a join would make a passage unreadable the moment its document row is gone — which is
 * exactly when somebody is trying to work out what the assistant just answered from.
 *
 * `document_id` is `VARCHAR(64)`, not `BINARY(16)`. `PassageStore::deleteDocument(string $documentId)`
 * promises no particular id format, and both existing implementations treat it as an opaque string —
 * `InMemoryPassageStore` compares it directly, and `AiStorePassageStore` stores it verbatim in the
 * vector row's metadata. The store that writes this table binds the id the same way, with no hex
 * conversion, so the column has to accept whatever string that store passes.
 */
class Migration1788393600CreateShopInfoPassages extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788393600;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in every migration here: a schema
     *         change that cannot apply must fail loudly rather than leave a plugin that boots and
     *         then errors on the first indexing run
     */
    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_assistant_shop_info_passage` (
                `id` BINARY(16) NOT NULL,
                `document_id` VARCHAR(64) NOT NULL,
                `document_name` VARCHAR(255) NOT NULL,
                `sales_channel_id` VARCHAR(32) NOT NULL,
                `section` VARCHAR(255) NOT NULL DEFAULT '',
                `text` LONGTEXT NOT NULL,
                `vector` JSON NOT NULL,
                `dimension` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.swag_assistant_shop_info_passage.channel` (`sales_channel_id`),
                KEY `idx.swag_assistant_shop_info_passage.document` (`document_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. Dropping this would destroy the merchant's indexed documents on a
        // plugin UPDATE; removal belongs to uninstall, where AssistantTableRemoval does it and only
        // when the merchant declined to keep their data.
    }
}
