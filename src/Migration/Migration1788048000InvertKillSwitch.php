<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Migration\Support\RenameSystemConfigKey;

/**
 * Carries `killSwitch` across to `assistantEnabled`, inverted.
 *
 * **Without this migration the rename silently restarts every stopped assistant.** A shop that had
 * deliberately switched the assistant off holds `killSwitch: true`. Rename the key in `config.xml`
 * alone and the new key is simply absent — and absent means the documented default, which is
 * *enabled*. The merchant's most deliberate decision in the whole form would be undone by an
 * update, with no message anywhere.
 *
 * A migration is the right vehicle rather than the plugin's `update()` hook: this rewrites stored
 * rows, it must run exactly once, and Shopware's migration table is the only thing here that
 * remembers whether it already has. {@see RenameSystemConfigKey} owns the per-channel read and the
 * delete; what is specific to this one is the inversion and its two traps.
 *
 * - **The string `"false"`.** `bin/console system:config:set` stores booleans as strings, so a
 *   channel switched off from the CLI holds `{"_value":"false"}` — and `(bool) "false"` is `true`,
 *   which would invert to *disabled* and stop an assistant that was running. The same
 *   `FILTER_VALIDATE_BOOLEAN` reading {@see \Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig} uses is applied here, for
 *   the same reason and against the same measured bug.
 * - **Idempotence.** A channel that somehow already holds `assistantEnabled` is left alone. The new
 *   value is the more recent statement of intent; overwriting it from a stale `killSwitch` row
 *   would be a migration undoing a merchant's later decision.
 */
class Migration1788048000InvertKillSwitch extends RenameSystemConfigKey
{
    public function getCreationTimestamp(): int
    {
        return 1788048000;
    }

    protected function oldSetting(): string
    {
        return 'killSwitch';
    }

    protected function newSetting(): string
    {
        return 'assistantEnabled';
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated to {@see RenameSystemConfigKey::update()}
     */
    protected function carry(Connection $connection, ?string $salesChannelId, string $storedValue): void
    {
        [$clause, $params] = $this->channelClause($this->key($this->newSetting()), $salesChannelId);

        $existing = $connection->fetchOne('SELECT `id` FROM `system_config`
             WHERE `configuration_key` = :key AND ' . $clause, $params);

        if ($existing !== false) {
            return;
        }

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => $this->key($this->newSetting()),
            'configuration_value' => json_encode(['_value' => !$this->wasKilled($storedValue)], \JSON_THROW_ON_ERROR),
            'sales_channel_id' => $salesChannelId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    /**
     * Reads one stored `killSwitch` value the way the runtime read it, string trap included.
     *
     * A value this cannot parse is treated as *not killed*, which resolves to `assistantEnabled:
     * true`. That is the direction to fail in: the alternative silently disables a working shop's
     * assistant over a row nobody can interpret.
     */
    private function wasKilled(string $storedValue): bool
    {
        $value = $this->decode($storedValue);

        return $value === null ? false : filter_var($value, \FILTER_VALIDATE_BOOLEAN);
    }
}
