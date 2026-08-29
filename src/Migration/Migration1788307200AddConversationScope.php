<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `swag_assistant_conversation.scope_type` and the two Commercial shopper ids beside it.
 *
 * **No foreign keys on the two commercial columns.** `commercial_employee_id` and
 * `commercial_organisation_id` would reference tables that belong to Shopware Commercial, an
 * optional extension that is not installed — and not in this shop's licence — so they are stored
 * as plain ids.
 *
 * **The backfill is bounded, historical ambiguity, not a guess.** Existing rows have no scope: one
 * with a `customer_id` was a customer's; one without is indistinguishable from a guest's,
 * including a row whose customer has since been deleted, because that foreign key is
 * `ON DELETE SET NULL` (see {@see Migration1787356800AddConversationCustomerId}). The `ALTER`
 * below gives every existing row `DEFAULT 'guest'`, and the second statement then promotes only
 * the rows that still have a `customer_id` to `customer` — so a deleted customer's old
 * conversation keeps reading as a guest's, exactly as it always has. What changes from this
 * migration forward is that a row's `scope_type` is written once and kept: a customer's
 * conversation stays `customer` even after that customer is deleted, which is what stops that
 * transcript becoming readable as a guest's.
 */
class Migration1788307200AddConversationScope extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788307200;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately, as in
     *         {@see Migration1787356800AddConversationCustomerId}: a schema change that cannot apply
     *         must fail loudly rather than leave a plugin that boots and then errors on first use.
     */
    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn('SHOW COLUMNS FROM `swag_assistant_conversation` LIKE :column', [
            'column' => 'scope_type',
        ]);

        if ($existing !== []) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `swag_assistant_conversation`
                ADD `scope_type` VARCHAR(16) NOT NULL DEFAULT \'guest\' AFTER `customer_id`,
                ADD `commercial_employee_id` BINARY(16) NULL AFTER `scope_type`,
                ADD `commercial_organisation_id` BINARY(16) NULL AFTER `commercial_employee_id`',
        );

        // Rows written before this migration carry no scope. One with a customer was a customer's;
        // one without is indistinguishable from a guest's, including a row whose customer has since
        // been deleted — the foreign key is ON DELETE SET NULL. Preserving existing guest
        // rehydration accepts that bounded, historical ambiguity. Every row written from now on
        // keeps its scope type when its customer is deleted, which is what stops that transcript
        // becoming readable as a guest's.
        $connection->executeStatement(
            'UPDATE `swag_assistant_conversation` SET `scope_type` = \'customer\' WHERE `customer_id` IS NOT NULL',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: both new columns are additive and every existing row keeps reading
        // exactly as it did before this migration ran.
    }
}
