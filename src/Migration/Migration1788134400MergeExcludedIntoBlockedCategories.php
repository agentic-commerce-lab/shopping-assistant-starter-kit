<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Migration\Support\RenameSystemConfigKey;

/**
 * Folds `excludedCategories` into `blockedCategories` and removes the duplicate setting.
 *
 * The two were never two things. `DalCriteriaBuilder` concatenated them into one array and applied
 * them as a single filter; the fixture gateway OR-ed two identical checks. What the settings form
 * offered was one control under two names, and the only behavioural difference anywhere ran the
 * wrong way round — `BlocklistFilter`'s second-line-of-defence pass covered the list called
 * *blocked* and not the one called *excluded*.
 *
 * **Dropping the field without moving its contents would un-hide categories.** A merchant who put
 * their spare-parts tree in the excluded box and nothing in the blocked one would find the assistant
 * recommending gaskets after an update.
 *
 * Merging is lossless precisely *because* the two filtered identically, and appending is the only
 * reading that cannot lose a decision when a channel has ids in both boxes. Duplicates are dropped:
 * the same id in both is a merchant hedging against exactly the ambiguity this removes, not a
 * request to filter it twice.
 */
class Migration1788134400MergeExcludedIntoBlockedCategories extends RenameSystemConfigKey
{
    public function getCreationTimestamp(): int
    {
        return 1788134400;
    }

    protected function oldSetting(): string
    {
        return 'excludedCategories';
    }

    protected function newSetting(): string
    {
        return 'blockedCategories';
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated to {@see RenameSystemConfigKey::update()}
     */
    protected function carry(Connection $connection, ?string $salesChannelId, string $storedValue): void
    {
        $excluded = $this->ids($storedValue);

        if ($excluded === []) {
            // An empty or whitespace-only box. Nothing to carry, and writing a blank
            // `blockedCategories` row where none existed would leave a shop looking configured —
            // which D5 calls worse than an absent blocklist.
            return;
        }

        [$clause, $params] = $this->channelClause($this->key($this->newSetting()), $salesChannelId);

        /** @var array{id: string, configuration_value: string}|false $existing */
        $existing = $connection->fetchAssociative('SELECT `id`, `configuration_value` FROM `system_config`
             WHERE `configuration_key` = :key AND '
        . $clause, $params);

        $merged = $existing === false
            ? $excluded
            : array_values(array_unique([...$this->ids($existing['configuration_value']), ...$excluded]));

        $value = json_encode(['_value' => implode("\n", $merged)], \JSON_THROW_ON_ERROR);

        if ($existing === false) {
            $connection->insert('system_config', [
                'id' => Uuid::randomBytes(),
                'configuration_key' => $this->key($this->newSetting()),
                'configuration_value' => $value,
                'sales_channel_id' => $salesChannelId,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);

            return;
        }

        $connection->update(
            'system_config',
            [
                'configuration_value' => $value,
                'updated_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
            ['id' => $existing['id']],
        );
    }

    /**
     * One id per line, trimmed, blanks dropped — the rule the runtime used on the day this runs.
     *
     * Reimplemented rather than shared with `SystemConfigAssistantConfig::idList()`, because a
     * migration must keep reading data the way it was written. Binding it to a method that is free
     * to change is how a migration silently starts doing something else two releases later.
     *
     * @return list<string>
     */
    private function ids(string $storedValue): array
    {
        $value = $this->decode($storedValue);
        $lines = \is_string($value) ? preg_split('/\R/', $value) : [];

        return array_values(array_filter(
            array_map(trim(...), $lines === false ? [] : $lines),
            static fn(string $line): bool => $line !== '',
        ));
    }
}
