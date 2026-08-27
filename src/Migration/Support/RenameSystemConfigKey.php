<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration\Support;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;

/**
 * **Lives in `Migration/Support/`, not beside the migrations, and that placement is load-bearing.**
 * `MigrationCollection::loadMigrationSteps()` `scandir()`s the migration directory, keeps every file
 * whose class `is_subclass_of(MigrationStep::class)` and calls `new` on it. An abstract base passes
 * that check and fatals on construction, which stops EVERY migration in the plugin from running — see
 * `MigrationDirectoryTest`. `scandir()` is not recursive and a directory entry fails the `.php`
 * extension check, so a subdirectory is invisible to it.
 *
 * The shape every "this setting became that setting" migration has.
 *
 * Retiring a `config.xml` field is always the same three steps, and getting any of them wrong is
 * always silent: read what merchants stored under the old key **per sales channel**, write the
 * equivalent under the new one, then delete the old rows. Skip the middle step and the setting
 * reverts to its default — which for an off switch means an assistant that comes back on, and for a
 * catalogue filter means hidden products reappearing.
 *
 * Only the middle step differs between subclasses, so only the middle step is abstract. The two
 * things this class guarantees are the two that are easy to get wrong twice:
 *
 * - **Per sales channel.** `system_config` holds one row per channel plus a null-channel default,
 *   and a shop can hold different decisions in each. Every row is offered to {@see self::carry()}
 *   separately.
 * - **The old rows go last.** Deleting first would lose the values a failed carry needed, and
 *   leaving them behind gives a future reader two settings that disagree with nothing to say which
 *   one is read.
 */
abstract class RenameSystemConfigKey extends MigrationStep
{
    /**
     * The setting being retired, named the way `config.xml` names it — no prefix.
     */
    abstract protected function oldSetting(): string;

    /**
     * The setting its value moves to.
     */
    abstract protected function newSetting(): string;

    /**
     * A bare setting name as `system_config` stores it.
     *
     * Prefixing lives here rather than in each subclass so that a migration reads as *"this setting
     * became that setting"* — which is the only thing it is ever about — and so that a plugin
     * renamed tomorrow does not need every historical migration edited to keep working.
     */
    final protected function key(string $setting): string
    {
        return SystemConfigAssistantConfig::PREFIX . $setting;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately: a data change that cannot apply must
     *         fail loudly rather than leave a shop reading a key nothing ever wrote
     */
    public function update(Connection $connection): void
    {
        /** @var list<array{sales_channel_id: string|null, configuration_value: string}> $rows */
        $rows = $connection->fetchAllAssociative('SELECT `sales_channel_id`, `configuration_value`
             FROM `system_config`
             WHERE `configuration_key` = :key', [
            'key' => $this->key($this->oldSetting()),
        ]);

        foreach ($rows as $row) {
            $this->carry($connection, $row['sales_channel_id'], $row['configuration_value']);
        }

        $connection->executeStatement('DELETE FROM `system_config` WHERE `configuration_key` = :key', [
            'key' => $this->key($this->oldSetting()),
        ]);
    }

    /**
     * `destructive()` is where Shopware puts changes it will not run automatically. Nothing in this
     * family qualifies: the old rows are already gone by the time it would be reached.
     */
    public function updateDestructive(Connection $connection): void {}

    /**
     * Write the equivalent of one stored value under the new key.
     *
     * @param string|null $salesChannelId null for the shop-wide default row
     * @param string      $storedValue    the raw JSON `system_config` holds, `{"_value": …}`
     *
     * @throws \Doctrine\DBAL\Exception
     */
    abstract protected function carry(Connection $connection, ?string $salesChannelId, string $storedValue): void;

    /**
     * The `_value` inside a stored row, or `null` when the row cannot be read.
     *
     * Every caller decides for itself what an unreadable row means, because the safe direction
     * differs: for an off switch it is "running", for a catalogue filter it is "carry nothing".
     */
    protected function decode(string $storedValue): mixed
    {
        $decoded = json_decode($storedValue, true);

        if (!\is_array($decoded) || !\array_key_exists('_value', $decoded)) {
            return null;
        }

        return $decoded['_value'];
    }

    /**
     * A `WHERE sales_channel_id …` fragment and its parameters, which differ because SQL has no
     * `= NULL`.
     *
     * @return array{string, array<string, string>}
     */
    protected function channelClause(string $key, ?string $salesChannelId): array
    {
        if ($salesChannelId === null) {
            return ['`sales_channel_id` IS NULL', ['key' => $key]];
        }

        return ['`sales_channel_id` = :channel', ['key' => $key, 'channel' => $salesChannelId]];
    }
}
