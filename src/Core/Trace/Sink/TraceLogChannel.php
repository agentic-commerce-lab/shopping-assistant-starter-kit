<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Sink;

/**
 * The Monolog channel and handler {@see LoggerTraceSink} writes to, as configuration the plugin
 * prepends onto the shop's own.
 *
 * **This exists because the `logTraces` setting did not work.** Its help text promises "one line to
 * your shop's log each time the assistant replies", and in a stock production shop it wrote nothing:
 * the sink logs at `info`, and Shopware's production `monolog` puts a `fingers_crossed` at
 * `action_level: error` in front of a `nested` rotating file handler that is itself `level: error`.
 * An `info` record is dropped even after a later error activates the buffer. Measured on a fresh
 * 6.7.13.1 shop on 2026-09-02: sixteen turns, `logTraces` on, and nothing in `var/log/`.
 *
 * In `dev` the handler is `level: debug` and the line appears, which is why the promise looked kept
 * every time anyone checked it. That is the shape of failure this project keeps naming: not an
 * error, just a feature quietly not happening.
 *
 * **Raising the level was not an option.** Only `error` clears that handler, and a turn that
 * succeeded is not an error — logging one as such would put every reply into a merchant's error
 * monitoring. So the records get a handler of their own, at a level chosen for them.
 *
 * A pure function returning an array, separate from
 * {@see \Swag\AssistantStarterKit\SwagAssistantStarterKit::build()}, for the reason
 * {@see \Swag\AssistantStarterKit\Core\Trace\Retention\PruneConversationsTaskHandler} is a thin
 * shell over its pruner: the logic is testable here and the wiring is one line there.
 */
final class TraceLogChannel
{
    /**
     * The channel name, and the file-name stem. `LoggerTraceSink`'s service carries
     * `<tag name="monolog.logger" channel="swag_assistant"/>`, which is what makes its injected
     * `logger` the channel logger rather than the shop-wide one.
     */
    public const NAME = 'swag_assistant';

    /**
     * Two weeks. Long enough to answer "why were replies failing on Tuesday", short enough that one
     * line per reply on a busy shop cannot fill a disk — a plugin that did that would be worse than
     * one that logged nothing.
     */
    private const MAX_FILES = 14;

    /**
     * @return array{channels: list<string>, handlers: array<string, array<string, mixed>>}
     */
    public static function monologConfig(): array
    {
        return [
            'channels' => [self::NAME],
            'handlers' => [
                self::NAME => [
                    'type' => 'rotating_file',
                    // `%kernel.logs_dir%` rather than a path of ours: a merchant's log rotation,
                    // disk monitoring and backup exclusions already point there. The environment is
                    // in the name because Shopware's own logs are named that way.
                    'path' => '%kernel.logs_dir%/' . self::NAME . '_%kernel.environment%.log',
                    'level' => 'info',
                    'max_files' => self::MAX_FILES,
                    // Inclusive, and only ours. A handler that took every channel would duplicate
                    // the whole shop's log into this file at a level its operator did not choose.
                    'channels' => ['type' => 'inclusive', 'elements' => [self::NAME]],
                ],
            ],
        ];
    }
}
