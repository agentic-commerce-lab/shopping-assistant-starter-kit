<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\Sink\TraceLogChannel;

/**
 * The `logTraces` setting promised "one line to your shop's log each time the assistant replies"
 * and, in a stock production shop, wrote nothing at all.
 *
 * Measured on 2026-09-02 on a fresh 6.7.13.1 shop: `logTraces` on, the sink running, and
 * `grep -rl "Assistant turn finished" var/log/` empty after sixteen turns.
 * {@see \Swag\AssistantStarterKit\Core\Trace\Sink\LoggerTraceSink} logs at `info`, and Shopware's
 * production `monolog` config puts a `fingers_crossed` at `action_level: error` in front of a
 * `nested` rotating file handler that is itself `level: error` — so an `info` record is discarded
 * even once a later error activates the buffer. In `dev` the handler is `level: debug` and the line
 * appears, which is why the promise looked kept everywhere it was ever checked.
 *
 * Raising the level was not available: only `error` passes that handler, and a turn that succeeded
 * is not an error. So the plugin brings its own handler, and this class is the config it prepends.
 */
#[CoversClass(TraceLogChannel::class)]
final class TraceLogChannelTest extends TestCase
{
    public function testItDeclaresItsOwnChannelSoTheRecordsAreSeparable(): void
    {
        self::assertSame([TraceLogChannel::NAME], TraceLogChannel::monologConfig()['channels']);
    }

    /**
     * The whole point: `info`, in a handler the shop's own `error` threshold does not gate.
     */
    public function testTheHandlerAcceptsInfoRecords(): void
    {
        self::assertSame('info', self::handler()['level']);
    }

    public function testItWritesBesideTheShopsOwnLogsRatherThanSomewhereOfItsOwnChoosing(): void
    {
        // `%kernel.logs_dir%` rather than a path of ours: a merchant's log rotation, disk
        // monitoring and backup exclusions are already pointed there.
        self::assertSame('%kernel.logs_dir%/swag_assistant_%kernel.environment%.log', self::handler()['path']);
    }

    /**
     * **Rotating and bounded.** One line per reply on a busy shop is a file that only grows, and a
     * plugin that fills a merchant's disk is worse than one that logs nothing.
     */
    public function testItRotatesAndKeepsABoundedNumberOfFiles(): void
    {
        $handler = self::handler();

        self::assertSame('rotating_file', $handler['type']);
        self::assertSame(14, $handler['max_files']);
    }

    /**
     * **Only our own records.** A handler that took every channel would duplicate the whole shop's
     * log into our file, at a level its operator did not choose.
     */
    public function testItTakesOnlyItsOwnChannel(): void
    {
        self::assertSame(['type' => 'inclusive', 'elements' => [TraceLogChannel::NAME]], self::handler()['channels']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function handler(): array
    {
        $handlers = TraceLogChannel::monologConfig()['handlers'];
        $handler = $handlers[TraceLogChannel::NAME] ?? null;

        if (!\is_array($handler)) {
            throw new \RuntimeException('The config declares no handler for its own channel.');
        }

        return $handler;
    }
}
