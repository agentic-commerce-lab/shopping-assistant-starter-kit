<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Sink;

use Psr\Log\LoggerInterface;
use Swag\AssistantStarterKit\Core\Agent\FailedTurn;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The shipped trace sink: one line per turn into the shop's own log.
 *
 * It exists so {@see TraceSinkInterface} has a consumer in this repository. `ARCHITECTURE.md` spent
 * months naming extension interfaces nothing implemented, and an interface with no caller is a guess
 * we would then owe stability on. This one is also the worked example a merchant copies.
 *
 * **On by default** — `config.xml`, `SystemConfigAssistantConfig` and the stored row all say
 * `true`, and this docblock claimed the opposite until 2026-09-02. The line carries no shopper text,
 * so the case for shipping it off was thin and the case against was concrete: the first time a
 * merchant needs to know why replies are failing is exactly when they discover the logging they
 * needed was never running, and switching it on afterwards does not produce yesterday's failures.
 * The flag is read per sales channel, which is what the `salesChannelId` parameter on the interface
 * is for — one shop can want this on one channel only.
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

        // An allowlist, built field by field — never the payload, and never `$trace->events()`.
        $this->logger->info('Assistant turn finished.', [
            'token' => $token,
            'salesChannelId' => $salesChannelId,
            'outcome' => self::outcome($trace),
            'elapsedMs' => $trace->turnElapsedMs(),
        ]);
    }

    /**
     * **Two stages, because a failed turn has no `turn.end`** and must not be given one (ruling
     * R40 — see {@see FailedTurn}). Reading only `turn.end` reported every agent failure as
     * `unknown`, which is the one word that reads as "we do not know" about the single turn a
     * merchant most needs described.
     *
     * `unknown` still exists, and now means it: a trace with neither stage.
     */
    private static function outcome(TraceRecorder $trace): string
    {
        $turnEnd = $trace->payload('turn.end');
        $outcome = \is_array($turnEnd) ? $turnEnd['outcome'] ?? null : null;

        if (\is_string($outcome)) {
            return $outcome;
        }

        if ($trace->payload(FailedTurn::STAGE) !== null) {
            return FailedTurn::OUTCOME;
        }

        return 'unknown';
    }
}
