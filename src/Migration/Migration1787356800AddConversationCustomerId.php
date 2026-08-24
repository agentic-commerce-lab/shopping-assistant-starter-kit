<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `swag_assistant_conversation.customer_id`, and the constraint that makes it forget.
 *
 * **`ON DELETE SET NULL` is the feature, not a detail.** When a customer deletes their account the
 * database severs the link itself: no erasure routine over this table, nothing for anyone to forget
 * to write, and no pseudonymous identifier left pointing at a transcript. The conversation
 * survives — a trace is a record of what the shop did, and losing it because a customer left would
 * destroy the merchant's own history. `ON DELETE CASCADE` would do exactly that, which is why it is
 * not used here. **Do not "tidy" this to CASCADE.**
 *
 * The consequence is deliberate: a deleted customer's conversation becomes indistinguishable from a
 * guest's. That is the trade — the distinction could only be kept by keeping a marker about someone
 * who asked to be forgotten.
 */
class Migration1787356800AddConversationCustomerId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787356800;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in
     *         {@see Migration1787270400AddTraceEventElapsedMs}: a schema change that cannot apply
     *         must fail loudly rather than leave a plugin that boots and then errors on first use.
     */
    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `swag_assistant_conversation` LIKE :column', [
            'column' => 'customer_id',
        ]);

        if ($existing !== []) {
            return;
        }

        $connection->executeStatement('ALTER TABLE `swag_assistant_conversation`
                ADD `customer_id` BINARY(16) NULL AFTER `sales_channel_id`,
                ADD CONSTRAINT `fk.swag_assistant_conversation.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the column is additive and every existing row reads as a guest.
    }
}
