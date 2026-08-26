<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Sink;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\Sink\LoggerTraceSink;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;

/**
 * The shipped sink, and the reason it exists: an interface with no consumer in-tree is a guess, and
 * this repo has been burned by documenting extension points nothing implemented.
 */
final class LoggerTraceSinkTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    public function testItLogsOneLinePerTurnWithoutTheMerchantHavingToFindTheSetting(): void
    {
        // **Default on, and it used to be off.** The line carries no shopper text — a channel, an
        // outcome, a duration — so the case for shipping it off was thin, and the case against was
        // concrete: the first time a merchant needs to know why replies are failing is exactly when
        // they discover the logging they needed was never running. Retroactively switching it on
        // does not produce yesterday's failures.
        $logger = new CollectingLogger();

        $this->sink($logger, [])->send('tok', self::CHANNEL, $this->trace());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
    }

    public function testItLogsNothingWhenTheMerchantSwitchesItOff(): void
    {
        // A stored false is a decision and must survive, the same way `enableAddToCart`'s does — a
        // default that overrode it would make the switch decorative.
        $logger = new CollectingLogger();

        $this->sink($logger, [self::PREFIX . 'logTraces' => false])->send('tok', self::CHANNEL, $this->trace());

        self::assertSame([], $logger->records);
    }

    public function testTheRecordCarriesTheOutcomeAndTheChannelButNoShopperText(): void
    {
        // The transcript is personal data and lives in a table with a retention task and access
        // control. A log file has neither, so this writes what a merchant needs to count turns and
        // nothing a shopper typed.
        $logger = new CollectingLogger();

        $this->sink($logger, [self::PREFIX . 'logTraces' => true])->send('tok', self::CHANNEL, $this->trace());

        $context = $logger->firstContext();

        self::assertSame(['token', 'salesChannelId', 'outcome', 'elapsedMs'], array_keys($context));
        self::assertSame('product_shown', $context['outcome']);
        self::assertSame(self::CHANNEL, $context['salesChannelId']);
    }

    /**
     * @param array<string, string|int|float|bool|null> $config
     */
    private function sink(CollectingLogger $logger, array $config): LoggerTraceSink
    {
        return new LoggerTraceSink($logger, new SystemConfigAssistantConfig(new FakeSystemConfigService($config)));
    }

    private function trace(): TraceRecorder
    {
        $trace = new TraceRecorder();
        // A payload carrying a shopper's own words, so the assertion above is about filtering rather
        // than about there being nothing to leak.
        $trace->record('query.build', ['terms' => ['blue trail jersey for my wife']]);
        $trace->record('turn.end', ['outcome' => 'product_shown']);

        return $trace;
    }
}

/** @internal */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * The first record's context, or a failed assertion.
     *
     * A method rather than an inline `$records[0]['context']` because the analyzer cannot narrow an
     * index into a list from an `assertArrayHasKey` call, and suppressing that would be hiding a real
     * class of mistake to silence one instance of it.
     *
     * @return array<string, mixed>
     */
    public function firstContext(): array
    {
        $first = $this->records[0] ?? null;

        if ($first === null) {
            throw new \RuntimeException('The logger recorded nothing.');
        }

        return $first['context'];
    }

    public function log($level, $message, array $context = []): void
    {
        // PSR-3 types the context as array<array-key, mixed>; narrowed here so the assertions above
        // can read it as the string-keyed allowlist LoggerTraceSink actually writes.
        $narrowed = [];
        foreach ($context as $key => $value) {
            $narrowed[(string) $key] = $value;
        }

        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $narrowed];
    }
}
