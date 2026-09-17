<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\JudgeReplayCommand;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\InsightJudge;
use Swag\AssistantStarterKit\Core\Insights\InsightMetrics;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettings;
use Swag\AssistantStarterKit\Core\Insights\InsightsSettingsReader;
use Swag\AssistantStarterKit\Core\Insights\Judge\ValidatedFindings;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The replay command is how decision D28 is satisfied, so the assertions are about the two things
 * that would make a precision figure wrong: reaching the model when nobody asked for it, and
 * failing on a file in a way that hides the reason.
 */
final class JudgeReplayCommandTest extends TestCase
{
    public function testDryRunReadsTheExportAndCallsNoModel(): void
    {
        // The corpus is 5.2 MB of real conversations. Checking that it parses and that the sample
        // is the size you expected costs nothing; the run after it costs money.
        $export = self::exportFile([self::conversation('A reply.', 'product_shown')]);

        $tester = new CommandTester(new JudgeReplayCommand(self::settings(), new UnreachableReplayJudge()));
        $tester->execute(['export' => $export, '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 conversations in the export', $tester->getDisplay());
        self::assertStringContainsString('no model was called', $tester->getDisplay());

        unlink($export);
    }

    public function testItAggregatesTheExportItWasGiven(): void
    {
        $export = self::exportFile([
            self::conversation('A reply.', 'cart_added'),
            self::conversation('Another.', 'product_shown'),
        ]);

        $tester = new CommandTester(new JudgeReplayCommand(self::settings(), new UnreachableReplayJudge()));
        $tester->execute(['export' => $export, '--dry-run' => true]);

        self::assertStringContainsString('"conversations":2', $tester->getDisplay());
        self::assertStringContainsString('"cartAdded":1', $tester->getDisplay());

        unlink($export);
    }

    public function testAMissingFileFailsWithAReasonRatherThanAStackTrace(): void
    {
        $tester = new CommandTester(new JudgeReplayCommand(self::settings(), new UnreachableReplayJudge()));
        $tester->execute(['export' => '/nowhere/at/all.json']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not readable', $tester->getDisplay());
    }

    public function testAFileThatIsNotATraceExportFailsWithAReason(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'judge') . '.json';
        file_put_contents($path, '"a string, not a list"');

        $tester = new CommandTester(new JudgeReplayCommand(self::settings(), new UnreachableReplayJudge()));
        $tester->execute(['export' => $path]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('list of conversations', $tester->getDisplay());

        unlink($path);
    }

    public function testASampleOfZeroNeverReachesTheModelEvenWithoutDryRun(): void
    {
        // JudgeBudget::fit() of an empty sample is empty, and the command must not call a judge
        // with nothing to judge — that would spend money on a request containing no conversations.
        $export = self::exportFile([self::conversation('A reply.', 'product_shown')]);

        $tester = new CommandTester(new JudgeReplayCommand(self::settings(), new UnreachableReplayJudge()));
        $tester->execute(['export' => $export, '--sample' => '0']);

        self::assertStringContainsString('0 in the sample', $tester->getDisplay());

        unlink($export);
    }

    /** @param list<array<string, mixed>> $conversations */
    private static function exportFile(array $conversations): string
    {
        $path = tempnam(sys_get_temp_dir(), 'judge') . '.json';
        file_put_contents($path, json_encode($conversations, \JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private static function conversation(string $prose, string $outcome): array
    {
        return [
            'summary' => [],
            'transcript' => [
                ['role' => 'user', 'prose' => 'a question', 'cardIds' => [], 'outcome' => 'ok'],
                ['role' => 'assistant', 'prose' => $prose, 'cardIds' => [], 'outcome' => 'ok'],
            ],
            'events' => [
                ['seq' => 2, 'stage' => 'turn.end', 'elapsedMs' => 5, 'payload' => ['outcome' => $outcome]],
                ['seq' => 1, 'stage' => 'tool.result', 'elapsedMs' => 1, 'payload' => ['name' => 'search_products']],
            ],
        ];
    }

    private static function settings(): InsightsSettingsReader
    {
        // Never reached in these tests: every one of them either dry-runs or draws an empty sample.
        // Failing here is how "no money is spent by accident" is asserted — the settings hold the
        // model and the key, so reading them at all means a call was about to happen.
        return new UnreadSettings();
    }
}

final readonly class UnreadSettings implements InsightsSettingsReader
{
    public function forSalesChannel(?string $salesChannelId = null): InsightsSettings
    {
        TestCase::fail('The settings must not be read before a judge call is due.');
    }
}

final class UnreachableReplayJudge implements InsightJudge
{
    public function run(InsightMetrics $metrics, array $traces, InsightsSettings $settings): ValidatedFindings
    {
        TestCase::fail('The judge must not be reached in this configuration.');
    }
}
