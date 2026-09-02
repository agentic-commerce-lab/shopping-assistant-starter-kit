<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Migration\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * **Lives in `Migration/Support/`, and that placement is load-bearing** for the reason
 * {@see RenameSystemConfigKey} sets out: `MigrationCollection::loadMigrationSteps()` `scandir()`s the
 * migration directory and calls `new` on everything in it, and `scandir()` is not recursive.
 *
 * What was in the three retired greeting fields, keyed by the language each one named.
 *
 * Split out of {@see \Swag\AssistantStarterKit\Migration\Migration1788307200MoveGreetingIntoSnippets}
 * because the two halves answer different questions. That class knows where a greeting is *written*
 * — one row per snippet set, never over an existing one. This one knows what a merchant *had*, which
 * means knowing every way `system_config` stores a string that is not really a greeting.
 *
 * ## One value per language, across every sales channel
 *
 * `system_config` is per sales channel and a snippet set is not, so a shop that set the same field
 * differently on two channels keeps one of them. The query puts the shop-wide row first — it is the
 * row that already applied everywhere — and between two channel rows the choice is by primary key and
 * arbitrary. That is the only lossy step in this migration, and the value it lands on is always one
 * the merchant did write.
 */
final readonly class RetiredGreetings
{
    /**
     * @param array<string, string> $byLanguage subtag, or `''` for the language-neutral field
     */
    private function __construct(
        private array $byLanguage,
    ) {}

    /**
     * @param list<string> $keys the retired settings, fully prefixed, longest suffix irrelevant
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public static function readFrom(Connection $connection, string $neutralKey, array $keys): self
    {
        /** @var list<array{configuration_key: string, configuration_value: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT `configuration_key`, `configuration_value`
             FROM `system_config`
             WHERE `configuration_key` IN (:keys)
             ORDER BY `sales_channel_id` IS NULL DESC, `id` ASC',
            ['keys' => $keys],
            ['keys' => ArrayParameterType::STRING],
        );

        $byLanguage = [];

        foreach ($rows as $row) {
            // `greetingDe` is `de`; the neutral key's own suffix is empty. Read off the key rather
            // than resolved against `ReplyLanguage`, because a migration must keep reading data the
            // way it was written and that class is free to gain or lose a language later.
            $language = strtolower(substr($row['configuration_key'], \strlen($neutralKey)));

            // The ORDER BY already put the widest-applying row first, so the first value wins.
            if (\array_key_exists($language, $byLanguage)) {
                continue;
            }

            $value = self::storedValue($row['configuration_value']);

            // Whitespace is how a merchant clears a textarea. Carried through it becomes a snippet
            // holding a space, which suppresses the shipped default and shows a blank bubble.
            if ($value !== '') {
                $byLanguage[$language] = $value;
            }
        }

        return new self($byLanguage);
    }

    public function isEmpty(): bool
    {
        return $this->byLanguage === [];
    }

    /**
     * The greeting a snippet set with this ISO should get, or null to leave it on the shipped default.
     *
     * Matched on the subtag, not the full locale: a shop can perfectly well run `de-CH`, and matching
     * `de-DE` exactly would leave it greeting in English while the assistant answers it in German.
     */
    public function forIso(string $iso): ?string
    {
        $language = strtolower(explode('-', $iso)[0]);

        return $this->byLanguage[$language] ?? $this->byLanguage[''] ?? null;
    }

    private static function storedValue(string $stored): string
    {
        $decoded = json_decode($stored, true);

        if (!\is_array($decoded) || !\is_string($decoded['_value'] ?? null)) {
            return '';
        }

        return trim($decoded['_value']);
    }
}
