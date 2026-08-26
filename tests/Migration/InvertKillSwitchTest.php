<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Migration\Migration1788048000InvertKillSwitch;

/**
 * The rename's data half.
 *
 * `killSwitch` and `assistantEnabled` are the same control stated in opposite directions, so the
 * migration is the only thing standing between a merchant's deliberate stop and an update that
 * silently undoes it. Every assertion here is about a way that could go wrong quietly.
 */
final class InvertKillSwitchTest extends TestCase
{
    private const OLD_KEY = 'SwagAssistantStarterKit.config.killSwitch';

    private const NEW_KEY = 'SwagAssistantStarterKit.config.assistantEnabled';

    private const CHANNEL = 'sales-channel-bytes';

    /** @var list<array<string, mixed>> */
    private array $inserts = [];

    /** @var list<string> */
    private array $deletedKeys = [];

    /**
     * The whole conversion table, in one place.
     *
     * Every row is a way the rename could quietly change what a merchant decided, and the two string
     * rows are the ones that would actually have shipped: `bin/console system:config:set` writes
     * booleans as strings, and `(bool) "false"` is `true`. A plain cast here would invert a
     * *running* assistant into a stopped one — the migration reproducing, at update time and across
     * every channel at once, the exact bug the runtime was already hardened against.
     *
     * @param string $stored what `system_config` holds for `killSwitch`
     * @param string $expected what `assistantEnabled` must end up holding
     */
    #[DataProvider('storedKillSwitchValues')]
    public function testAStoredKillSwitchIsCarriedAcrossInverted(string $stored, string $expected): void
    {
        $inserts = $this->carry([
            ['sales_channel_id' => self::CHANNEL, 'configuration_value' => $stored],
        ]);

        self::assertCount(1, $inserts);
        self::assertSame(self::NEW_KEY, $inserts[0]['configuration_key']);
        self::assertSame($expected, $inserts[0]['configuration_value']);
        self::assertSame(self::CHANNEL, $inserts[0]['sales_channel_id']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function storedKillSwitchValues(): iterable
    {
        // The failure this migration exists for: without it the new key is absent, absent means
        // enabled, and a shop that switched the assistant off finds it answering shoppers again
        // after an update — with nothing in the interface saying so.
        yield 'a stopped assistant stays stopped' => ['{"_value":true}', '{"_value":false}'];

        yield 'a running assistant stays running' => ['{"_value":false}', '{"_value":true}'];

        yield 'the CLI string "false" is not read as stopped' => ['{"_value":"false"}', '{"_value":true}'];

        yield 'the CLI string "true" is still a stop' => ['{"_value":"true"}', '{"_value":false}'];

        // Nobody can say what this row meant. Guessing "stopped" takes a working shop's assistant
        // away over a value nobody can interpret; guessing "running" leaves the merchant with a
        // switch they can see and flip.
        yield 'an unreadable row fails towards a running assistant' => ['not json at all', '{"_value":true}'];
    }

    public function testEachSalesChannelIsCarriedSeparately(): void
    {
        // A shop can perfectly well run the assistant in one channel and not in another. Collapsing
        // the rows would impose one channel's decision on every other.
        $inserts = $this->carry([
            ['sales_channel_id' => null, 'configuration_value' => '{"_value":false}'],
            ['sales_channel_id' => self::CHANNEL, 'configuration_value' => '{"_value":true}'],
        ]);

        self::assertCount(2, $inserts);
        self::assertNull($inserts[0]['sales_channel_id']);
        self::assertSame('{"_value":true}', $inserts[0]['configuration_value']);
        self::assertSame(self::CHANNEL, $inserts[1]['sales_channel_id']);
        self::assertSame('{"_value":false}', $inserts[1]['configuration_value']);
    }

    public function testTheOldRowsAreDeleted(): void
    {
        // Two settings that disagree, with nothing saying which one the assistant reads, is worse
        // than either of them alone.
        $this->connection([
            ['sales_channel_id' => self::CHANNEL, 'configuration_value' => '{"_value":true}'],
        ]);

        self::assertSame([self::OLD_KEY], $this->deletedKeys);
    }

    public function testAChannelThatAlreadyHoldsTheNewKeyIsLeftAlone(): void
    {
        // Idempotence, and more than that: the new value is the later statement of intent, so a
        // re-run must not reach back and overwrite it from a stale row.
        $this->connection([[
            'sales_channel_id' => self::CHANNEL,
            'configuration_value' => '{"_value":true}',
        ]], existing: true);

        self::assertSame([], $this->inserts);
        self::assertSame([self::OLD_KEY], $this->deletedKeys);
    }

    public function testAShopThatNeverStoredTheSettingIsUntouched(): void
    {
        $this->connection([]);

        self::assertSame([], $this->inserts);
    }

    /**
     * @param list<array{sales_channel_id: string|null, configuration_value: string}> $rows
     *
     * @return list<array<string, mixed>> the rows the migration decided to write
     */
    private function carry(array $rows): array
    {
        $this->connection($rows);

        return $this->inserts;
    }

    /**
     * A `Connection` that records rather than connects.
     *
     * A mock rather than a subclass, deliberately: `Doctrine\DBAL\Connection` is a concrete class
     * with a two-argument constructor and union parameter types, and a hand-written stub of it spent
     * more lines satisfying the static analyser about signatures nothing here calls than describing
     * what the migration is allowed to touch. What this test has to know is only *what gets written*.
     *
     * The migration is run here rather than by the caller, so that every case gets the same
     * three-step arrangement and no test can accidentally assert against an un-run migration.
     *
     * @param list<array{sales_channel_id: string|null, configuration_value: string}> $rows
     */
    private function connection(array $rows, bool $existing = false): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAllAssociative')->willReturn($rows);

        // `false` is what DBAL returns for no row, and it is what the migration's idempotence check
        // reads. A `null` here would make every channel look like it already holds the new key.
        $connection->method('fetchOne')->willReturn($existing ? 'an-existing-id' : false);

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

        (new Migration1788048000InvertKillSwitch())->update($connection);
    }
}
