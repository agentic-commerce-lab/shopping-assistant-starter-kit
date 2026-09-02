<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Migration\Support\RetiredGreetings;

/**
 * Carries `greeting`, `greetingDe` and `greetingEn` into the snippet the storefront falls back to.
 *
 * **Not a {@see Support\RenameSystemConfigKey} subclass**, and the reason is the shape of the move
 * rather than a preference: that base offers one stored row at a time and writes the equivalent under
 * another `system_config` key. This collapses three keys across every sales channel onto a different
 * axis entirely — one value per snippet set — so it has to see all three at once to know which set a
 * value belongs in, and it writes to a table that base class knows nothing about.
 *
 * ## Two dimensions, and only one of them survives
 *
 * `system_config` is per sales channel and has no translation layer; a snippet set is per language and
 * knows nothing about channels. The old design bought the channel axis and paid for it with a field
 * per language — two languages, hard-coded, each costing a `config.xml` entry. This buys the language
 * axis instead, for every language the shop has snippet sets for, and gives up the channel one. A
 * merchant who does need it per channel gets there the way Shopware intends: a snippet set per
 * domain, which is already the only way to vary any *other* string this widget shows a shopper.
 *
 * Which value a shop keeps when it set the same field differently per channel is
 * {@see RetiredGreetings}'s decision, and the one lossy step here.
 *
 * ## What is never overwritten
 *
 * A snippet set that already holds this key is left exactly as it is. That row is an override the
 * merchant made in the Administration and can see there; the `system_config` value is one they left
 * in a form. Overwriting the visible edit with the invisible one is the wrong way round.
 */
class Migration1788307200MoveGreetingIntoSnippets extends MigrationStep
{
    /**
     * The key the storefront already renders when no override exists, so a shop that carried nothing
     * is a shop that keeps the greeting it has.
     */
    private const SNIPPET = 'swagAssistant.panel.defaultGreeting';

    /**
     * Snippets carry an author and the Administration shows it. Naming the plugin rather than a user
     * is what tells a merchant why a row they never typed is sitting in their snippet set.
     */
    private const AUTHOR = 'SwagAssistantStarterKit';

    public function getCreationTimestamp(): int
    {
        return 1_788_307_200;
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated deliberately: a greeting that cannot be carried must
     *         fail loudly rather than be deleted from a form and written nowhere
     */
    public function update(Connection $connection): void
    {
        $greetings = RetiredGreetings::readFrom($connection, $this->neutralKey(), $this->keys());

        if (!$greetings->isEmpty()) {
            $this->write($connection, $greetings);
        }

        // Last, and only after the writes. Deleting first would lose the values a failed carry needed.
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` IN (:keys)',
            ['keys' => $this->keys()],
            ['keys' => ArrayParameterType::STRING],
        );
    }

    /**
     * `destructive()` is where Shopware puts changes it will not run automatically. Nothing here
     * qualifies: the fields are gone from `config.xml` in the same release, so leaving their rows
     * behind would strand them under keys no form offers and no code reads.
     */
    public function updateDestructive(Connection $connection): void {}

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function write(Connection $connection, RetiredGreetings $greetings): void
    {
        /** @var list<string> $claimed */
        $claimed = $connection->fetchFirstColumn('SELECT LOWER(HEX(`snippet_set_id`)) FROM `snippet` WHERE `translation_key` = :key', [
            'key' => self::SNIPPET,
        ]);

        /** @var list<array{id: string, iso: string}> $sets */
        $sets = $connection->fetchAllAssociative('SELECT LOWER(HEX(`id`)) AS `id`, `iso` FROM `snippet_set`');

        foreach ($sets as $set) {
            $value = \in_array($set['id'], $claimed, strict: true) ? null : $greetings->forIso($set['iso']);

            if ($value === null) {
                continue;
            }

            $connection->insert('snippet', [
                'id' => Uuid::randomBytes(),
                'snippet_set_id' => $set['id'],
                'translation_key' => self::SNIPPET,
                'value' => $value,
                'author' => self::AUTHOR,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }
    }

    private function neutralKey(): string
    {
        return SystemConfigAssistantConfig::PREFIX . 'greeting';
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return [$this->neutralKey(), $this->neutralKey() . 'De', $this->neutralKey() . 'En'];
    }
}
