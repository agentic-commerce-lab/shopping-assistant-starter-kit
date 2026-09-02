<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Migration\Migration1788307200MoveGreetingIntoSnippets;

/**
 * Retiring three greeting fields into the one snippet the storefront already falls back to.
 *
 * **Why the greeting stopped being a `system_config` value.** Every other string this widget shows a
 * shopper — the panel heading, the suggestion chips, the error and handover lines — is a snippet,
 * resolved per snippet set like the rest of the storefront. The greeting was the one exception, and
 * it paid for that by needing a *field per language* (`greetingDe`, `greetingEn`) because
 * `system_config` has no translation layer. `sw-snippet-field` removes the reason that exception
 * existed: the snippet is now editable in the plugin's own configuration form, which was the only
 * argument for keeping the greeting out of the snippet system in the first place.
 *
 * **What is deliberately lost.** `system_config` is per sales channel and a snippet set is not, so a
 * shop that had different greetings on two channels keeps one of them. That trade is the point rather
 * than an oversight: a greeting varies by language far more often than by channel, the old design
 * could only ever offer two languages, and a merchant who does need it per channel gets there the way
 * Shopware intends — a snippet set per domain, which is already how every other string in this widget
 * would have to be varied.
 */
final class MoveGreetingIntoSnippetsTest extends TestCase
{
    private const KEY = 'swagAssistant.panel.defaultGreeting';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    /** @var list<array<string, mixed>> */
    private array $inserts = [];

    /** @var list<string> */
    private array $statements = [];

    public function testTheGermanGreetingLandsInTheGermanSnippetSet(): void
    {
        $this->migrate(config: ['greetingDe' => 'Willkommen.', 'greetingEn' => 'Welcome.'], sets: [
            'set-de' => 'de-DE',
            'set-en' => 'en-GB',
        ]);

        self::assertSame(['set-de' => 'Willkommen.', 'set-en' => 'Welcome.'], $this->written());
    }

    public function testASetIsMatchedOnItsLanguageAndNotOnItsFullLocale(): void
    {
        // `ReplyLanguage` keys everything in this plugin on the subtag, and a shop can perfectly well
        // run de-CH. Matching `de-DE` exactly would leave that set on the shipped default while the
        // assistant answers it in German.
        $this->migrate(config: ['greetingDe' => 'Willkommen.'], sets: ['set-ch' => 'de-CH']);

        self::assertSame(['set-ch' => 'Willkommen.'], $this->written());
    }

    public function testTheNeutralGreetingFillsTheSetsNoLanguageFieldClaimed(): void
    {
        // The single-language shop: one box filled, and it must reach every storefront it used to.
        $this->migrate(config: ['greeting' => 'Welcome.', 'greetingDe' => 'Willkommen.'], sets: [
            'set-de' => 'de-DE',
            'set-en' => 'en-GB',
            'set-fr' => 'fr-FR',
        ]);

        self::assertSame(['set-de' => 'Willkommen.', 'set-en' => 'Welcome.', 'set-fr' => 'Welcome.'], $this->written());
    }

    public function testASetThatAlreadyHasAGreetingIsLeftAlone(): void
    {
        // A merchant who had already overridden the snippet made the more specific choice of the two,
        // and it is the one they can see in the Administration. Overwriting it would silently undo an
        // edit to make room for a value the same person had left in a form.
        $this->migrate(
            config: ['greeting' => 'From the config form.'],
            sets: ['set-en' => 'en-GB'],
            claimed: ['set-en'],
        );

        self::assertSame([], $this->written());
    }

    public function testTheRetiredFieldsAreDeleted(): void
    {
        // Left behind they are three settings no code reads, sitting under keys `config.xml` no longer
        // offers — which is how a future reader concludes the greeting is configured somewhere it is
        // not.
        $this->migrate(config: ['greeting' => 'Welcome.'], sets: ['set-en' => 'en-GB']);

        self::assertCount(1, $this->statements);

        $statement = $this->statements[0] ?? '';
        self::assertStringContainsString('DELETE FROM `system_config`', $statement);
    }

    public function testAShopThatNeverConfiguredAGreetingWritesNothing(): void
    {
        $this->migrate(config: [], sets: ['set-en' => 'en-GB']);

        self::assertSame([], $this->written());
    }

    public function testAWhitespaceOnlyGreetingIsNotCarried(): void
    {
        // Whitespace is how a merchant "clears" a textarea. Carried through it would become a snippet
        // holding a space, which suppresses the shipped default and shows a blank bubble — the exact
        // failure the runtime trims for today.
        $this->migrate(config: ['greeting' => '   '], sets: ['set-en' => 'en-GB']);

        self::assertSame([], $this->written());
    }

    /**
     * @return array<string, string> snippet set id => value written
     */
    private function written(): array
    {
        $bySet = [];

        foreach ($this->inserts as $insert) {
            self::assertSame(self::KEY, $insert['translation_key']);
            self::assertIsString($insert['snippet_set_id']);
            self::assertIsString($insert['value']);

            $bySet[$insert['snippet_set_id']] = $insert['value'];
        }

        return $bySet;
    }

    /**
     * @param array<string, string> $config settings name => stored greeting
     * @param array<string, string> $sets   snippet set id => iso
     * @param list<string>          $claimed snippet set ids that already hold the greeting
     */
    private function migrate(array $config, array $sets, array $claimed = []): void
    {
        $connection = $this->createMock(Connection::class);

        $rows = [];
        foreach ($config as $name => $value) {
            $rows[] = [
                'configuration_key' => self::PREFIX . $name,
                'configuration_value' => json_encode(['_value' => $value], \JSON_THROW_ON_ERROR),
            ];
        }

        $setRows = [];
        foreach ($sets as $id => $iso) {
            $setRows[] = ['id' => $id, 'iso' => $iso];
        }

        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(
                /** @return list<array<string, mixed>> */
                static fn(string $sql): array => str_contains($sql, 'snippet_set') ? $setRows : $rows,
            );

        $connection->method('fetchFirstColumn')->willReturn($claimed);

        $connection
            ->method('insert')
            ->willReturnCallback(
                /** @param array<string, mixed> $data */
                function (string $table, array $data): int {
                    self::assertSame('snippet', $table);
                    $this->inserts[] = $data;

                    return 1;
                },
            );

        $connection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                $this->statements[] = $sql;

                return 1;
            });

        (new Migration1788307200MoveGreetingIntoSnippets())->update($connection);
    }
}
