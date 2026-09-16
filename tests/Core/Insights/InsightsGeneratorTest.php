<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\CompletedRun;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\ConversationTraceSource;
use Swag\AssistantStarterKit\Core\Insights\InsightJudge;
use Swag\AssistantStarterKit\Core\Insights\InsightMetrics;
use Swag\AssistantStarterKit\Core\Insights\InsightRunSink;
use Swag\AssistantStarterKit\Core\Insights\InsightsGenerator;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettings;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettingsReader;
use Swag\AssistantStarterKit\Core\Insights\InsightWindow;
use Swag\AssistantStarterKit\Core\Insights\Judge\ValidatedFindings;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;

/**
 * The generator's job is the ORDER of four steps and what survives each one failing. These
 * assertions are about that order; the pieces themselves are tested on their own.
 */
final class InsightsGeneratorTest extends TestCase
{
    public function testWhenDisabledItWritesNothingAndSpendsNothing(): void
    {
        // The judge double fails the test if it is reached at all, which is the only way to assert
        // "spends nothing" without a billing API.
        $sink = new RecordingRunSink();

        $id = self::generator(new InsightsSettings(enabled: false, llm: self::llm()), new UnreachableJudge(), $sink, [])
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        self::assertNull($id);
        self::assertSame([], $sink->written);
    }

    public function testAJudgeFailureLeavesTheMetricsIntactAndRecordsTheReason(): void
    {
        // The aggregation is free and correct; losing it to the judge's bad night would be the
        // worst possible trade. This is the whole reason the judge call sits inside a try.
        $sink = new RecordingRunSink();

        self::generator(
            new InsightsSettings(enabled: true, samplePercent: 100, llm: self::llm()),
            new ThrowingJudge(new \JsonException('The judge answered with prose.')),
            $sink,
            [self::trace('c1', 'cart_added')],
        )
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        $run = $sink->written[0] ?? null;
        self::assertNotNull($run, 'a run must be written even when the judge fails');
        self::assertSame('The judge answered with prose.', $run->judgeError);
        self::assertSame(1, $run->metrics->counts()['cartAdded']);
    }

    public function testATransportFailureIsRecordedTheSameWayAsABadAnswer(): void
    {
        // Measured on 2026-09-16: one eval journey errored on a transport hiccup and passed on
        // re-run. A nightly task must treat that as "no findings tonight", never as an outage.
        $sink = new RecordingRunSink();

        self::generator(
            new InsightsSettings(enabled: true, samplePercent: 100, llm: self::llm()),
            new ThrowingJudge(new LlmException('502 from the provider.')),
            $sink,
            [self::trace('c1', 'product_shown')],
        )
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        $run = $sink->written[0] ?? null;
        self::assertNotNull($run, 'a transport failure must still write the metrics');
        self::assertSame('502 from the provider.', $run->judgeError);
        self::assertSame([], $run->findings);
    }

    public function testASamplePercentOfZeroAggregatesAndNeverAsksTheJudge(): void
    {
        $sink = new RecordingRunSink();

        self::generator(
            new InsightsSettings(enabled: true, samplePercent: 0, llm: self::llm()),
            new UnreachableJudge(),
            $sink,
            [self::trace('c1', 'product_shown')],
        )
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        $run = $sink->written[0] ?? null;
        self::assertNotNull($run);
        self::assertSame(1, $run->metrics->counts()['conversations']);
        self::assertSame(0, $run->sampled);
    }

    public function testAnEmptyNightWritesARunWithZerosRatherThanNothing(): void
    {
        // A missing row and a quiet night look identical in a trend chart. A row of zeros is the
        // honest one, and it is also what moves the window forward.
        $sink = new RecordingRunSink();

        self::generator(new InsightsSettings(enabled: true, llm: self::llm()), new UnreachableJudge(), $sink, [])
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        self::assertCount(1, $sink->written);
        $run = $sink->written[0] ?? null;
        self::assertNotNull($run);
        self::assertSame(0, $run->metrics->counts()['conversations']);
    }

    public function testTheSecondWindowStartsWhereTheFirstEnded(): void
    {
        $sink = new RecordingRunSink();
        $sink->lastEnd = new \DateTimeImmutable('2026-09-15 03:00:00');

        self::generator(
            new InsightsSettings(enabled: true, samplePercent: 0, llm: self::llm()),
            new UnreachableJudge(),
            $sink,
            [],
        )
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        $run = $sink->written[0] ?? null;
        self::assertNotNull($run);
        self::assertEquals($sink->lastEnd, $run->window->start);
    }

    public function testTheSeedIsRecordedSoTheSampleCanBeRedrawn(): void
    {
        $sink = new RecordingRunSink();

        self::generator(
            new InsightsSettings(enabled: true, samplePercent: 100, llm: self::llm()),
            new SilentJudge(),
            $sink,
            [self::trace('c1', 'product_shown')],
        )
            ->generate(new \DateTimeImmutable('2026-09-16 03:00:00'));

        $run = $sink->written[0] ?? null;
        self::assertNotNull($run);
        self::assertNotSame('', $run->sampleSeed);
    }

    private static function llm(): LlmSettings
    {
        return new LlmSettings('https://example.test', 'k', 'm');
    }

    private static function trace(string $id, string $outcome): ConversationTrace
    {
        return new ConversationTrace(
            $id,
            new \DateTimeImmutable('2026-09-15 12:00:00'),
            [
                ['seq' => 1, 'stage' => 'turn.end', 'payload' => ['outcome' => $outcome]],
            ],
            [['role' => 'assistant', 'prose' => 'A reply.']],
        );
    }

    /** @param list<ConversationTrace> $traces */
    private static function generator(
        InsightsSettings $settings,
        InsightJudge $judge,
        InsightRunSink $sink,
        array $traces,
    ): InsightsGenerator {
        return new InsightsGenerator(new FixedSettingsReader($settings), new FixedTraceSource($traces), $judge, $sink);
    }
}

/** Captures what would have been written, so the four steps can be asserted without a database. */
final class RecordingRunSink implements InsightRunSink
{
    /** @var list<CompletedRun> */
    public array $written = [];

    public ?\DateTimeImmutable $lastEnd = null;

    public function lastWindowEnd(): ?\DateTimeImmutable
    {
        return $this->lastEnd;
    }

    public function write(CompletedRun $run): string
    {
        $this->written[] = $run;

        return 'run-id';
    }
}

final readonly class FixedSettingsReader implements InsightsSettingsReader
{
    public function __construct(
        private InsightsSettings $settings,
    ) {}

    public function forSalesChannel(?string $salesChannelId = null): InsightsSettings
    {
        return $this->settings;
    }
}

final readonly class FixedTraceSource implements ConversationTraceSource
{
    /** @param list<ConversationTrace> $traces */
    public function __construct(
        private array $traces,
    ) {}

    /** @return list<ConversationTrace> */
    public function inWindow(InsightWindow $window): array
    {
        return $this->traces;
    }
}

/** Fails the test if the judge is reached; that is how "spends nothing" is asserted. */
final class UnreachableJudge implements InsightJudge
{
    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): ValidatedFindings
    {
        TestCase::fail('The judge must not be reached in this configuration.');
    }
}

final readonly class ThrowingJudge implements InsightJudge
{
    public function __construct(
        private \Throwable $failure,
    ) {}

    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): ValidatedFindings
    {
        throw $this->failure;
    }
}

final class SilentJudge implements InsightJudge
{
    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): ValidatedFindings
    {
        return new ValidatedFindings([], 0);
    }
}
