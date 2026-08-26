<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Migration\Migration1788134400MergeExcludedIntoBlockedCategories;

/**
 * Removing a duplicate setting without losing what merchants put in it.
 *
 * `excludedCategories` and `blockedCategories` filtered identically — `DalCriteriaBuilder`
 * concatenated them before building a single filter — so merging is lossless by construction. What
 * is *not* safe is dropping the field: a shop that used only the excluded box and left the blocked
 * one empty would start recommending the categories it had hidden, with no message anywhere.
 */
final class MergeExcludedIntoBlockedCategoriesTest extends TestCase
{
    private const OLD_KEY = 'SwagAssistantStarterKit.config.excludedCategories';

    private const NEW_KEY = 'SwagAssistantStarterKit.config.blockedCategories';

    private const CHANNEL = 'sales-channel-bytes';

    /** @var list<array<string, mixed>> */
    private array $inserts = [];

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    /** @var list<string> */
    private array $deletedKeys = [];

    public function testTheIdsMoveToTheBlockedListWhenItWasEmpty(): void
    {
        // The common case, and the one that would have un-hidden a catalogue: most shops that used
        // one of the two boxes used only one.
        $this->carry(excluded: "cat-1\ncat-2", blocked: null);

        self::assertCount(1, $this->inserts);
        self::assertSame(self::NEW_KEY, $this->inserts[0]['configuration_key']);
        self::assertSame('{"_value":"cat-1\ncat-2"}', $this->inserts[0]['configuration_value']);
        self::assertSame(self::CHANNEL, $this->inserts[0]['sales_channel_id']);
    }

    public function testExistingBlockedIdsAreKeptAndTheExcludedOnesAppended(): void
    {
        // Merge, never overwrite: a channel can have ids in both boxes, and appending is the only
        // reading that cannot lose a decision.
        $this->carry(excluded: 'cat-2', blocked: 'cat-1');

        self::assertSame([], $this->inserts);
        self::assertCount(1, $this->updates);
        self::assertSame('{"_value":"cat-1\ncat-2"}', $this->updates[0]['configuration_value']);
    }

    public function testAnIdInBothBoxesIsNotWrittenTwice(): void
    {
        // A merchant hedging against exactly the ambiguity this migration removes, not a request
        // to filter the same category twice.
        $this->carry(excluded: "cat-1\ncat-3", blocked: "cat-1\ncat-2");

        self::assertSame('{"_value":"cat-1\ncat-2\ncat-3"}', $this->updates[0]['configuration_value']);
    }

    public function testAnEmptyExcludedBoxWritesNothing(): void
    {
        // Writing a blank `blockedCategories` row where none existed would leave a shop looking
        // configured, and D5 makes a blocklist that silently does nothing worse than an absent one.
        $this->carry(excluded: "\n   \n", blocked: null);

        self::assertSame([], $this->inserts);
        self::assertSame([], $this->updates);
    }

    public function testTheOldRowsAreDeleted(): void
    {
        // Two settings that filter the same thing, with nothing saying which one is read, is the
        // state this migration exists to end.
        $this->carry(excluded: 'cat-1', blocked: null);

        self::assertSame([self::OLD_KEY], $this->deletedKeys);
    }

    public function testAShopThatNeverUsedTheFieldIsUntouched(): void
    {
        $this->migrate([]);

        self::assertSame([], $this->inserts);
        self::assertSame([], $this->updates);
    }

    private function carry(string $excluded, ?string $blocked): void
    {
        $this->migrate(
            [['sales_channel_id' => self::CHANNEL, 'configuration_value' => $this->stored($excluded)]],
            $blocked === null ? false : ['id' => 'an-existing-id', 'configuration_value' => $this->stored($blocked)],
        );
    }

    private function stored(string $value): string
    {
        return json_encode(['_value' => $value], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{sales_channel_id: string|null, configuration_value: string}> $rows
     * @param array{id: string, configuration_value: string}|false                    $existing
     */
    private function migrate(array $rows, array|false $existing = false): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAllAssociative')->willReturn($rows);
        $connection->method('fetchAssociative')->willReturn($existing === false ? false : $existing);

        $connection
            ->method('insert')
            ->willReturnCallback(
                /**
                 * @param array<string, mixed> $data
                 */
                function (string $table, array $data): int {
                    $this->inserts[] = $data;

                    return 1;
                },
            );

        $connection
            ->method('update')
            ->willReturnCallback(
                /**
                 * @param array<string, mixed> $data
                 */
                function (string $table, array $data): int {
                    $this->updates[] = $data;

                    return 1;
                },
            );

        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                /**
                 * @param array<string, mixed> $params
                 */
                function (string $sql, array $params = []): int {
                    if (str_contains($sql, 'DELETE')) {
                        $this->deletedKeys[] = (string) $params['key'];
                    }

                    return 1;
                },
            );

        (new Migration1788134400MergeExcludedIntoBlockedCategories())->update($connection);
    }
}
