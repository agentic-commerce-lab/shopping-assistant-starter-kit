<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Sink;

use Psr\Log\LoggerInterface;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The shipped trace sink: one line per turn into the shop's own log.
 *
 * It exists so {@see TraceSinkInterface} has a consumer in this repository. `ARCHITECTURE.md` spent
 * months naming extension interfaces nothing implemented, and an interface with no caller is a guess
 * we would then owe stability on. This one is also the worked example a merchant copies.
 *
 * **Off by default**, because a shop that never opened the setting must not silently start writing a
 * line per turn into its production log. The flag is read per sales channel, which is what the
 * `salesChannelId` parameter on the interface is for — one shop can want this on one channel only.
 *
 * **It logs no shopper text.** The trace holds payloads that quote a shopper's own words — the terms
 * they searched for, among others — and the transcript that holds the rest lives in a table with a
 * retention task and access control behind it. A log file has neither, so what goes out here is an
 * allowlist: which conversation, which channel, how it ended, how long it took. That is enough to
 * count turns and spot a slow one, and nothing a data-protection review has to think about.
 */
final readonly class LoggerTraceSink implements TraceSinkInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private SystemConfigAssistantConfig $configFactory,
    ) {}

    public function send(#[\SensitiveParameter] string $token, string $salesChannelId, TraceRecorder $trace): void
    {
        if (!$this->configFactory->forSalesChannel($salesChannelId)->logTraces) {
            return;
        }

        $turnEnd = $trace->payload('turn.end');
        $outcome = \is_array($turnEnd) ? $turnEnd['outcome'] ?? null : null;

        // An allowlist, built field by field — never the payload, and never `$trace->events()`.
        $this->logger->info('Assistant turn finished.', [
            'token' => $token,
            'salesChannelId' => $salesChannelId,
            'outcome' => \is_string($outcome) ? $outcome : 'unknown',
            'elapsedMs' => $trace->turnElapsedMs(),
        ]);
    }
}
