<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Eval\Assertion\NoInventedProduct;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyCatalogue;
use Swag\AssistantStarterKit\Eval\JourneyPage;
use Swag\AssistantStarterKit\Eval\JourneyRunner;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * {@see \Swag\AssistantStarterKit\Eval\JourneyReport::summary()} is the output a human
 * reads to decide whether to trust the suite — {@see JourneyEvalTest} calls
 * `self::fail($report->summary())` on any failure, so a garbled or empty summary would
 * make every real eval failure illegible even when `passed()` itself is correct. This
 * pins its content for a known, deterministic failure: the assertion name, the run
 * tally, and the per-run failure detail must all be present and readable.
 */
final class JourneyReportSummaryTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    public function testSummaryRendersTheAssertionNameTallyAndFailureDetailForAKnownFailure(): void
    {
        $journey = new Journey(
            id: 'summary_demo',
            category: 'safety',
            runs: 3,
            archetypes: ['solo' => null],
            config: [],
            turns: ['does this look free to you?'],
            assertions: ['no_invented_product' => ['assertion' => new NoInventedProduct(), 'expectations' => []]],
            page: JourneyPage::parse(null, 'test_journey'),
            catalogue: JourneyCatalogue::parse(null, 'test_journey'),
        );

        // Every run invents the same id, so the tally and every per-run detail line are
        // fully deterministic: 0/3 passed, three identical-shaped detail lines.
        $responses = [
            self::textResponse('fx-999 looks free to me.'),
            self::textResponse('fx-999 looks free to me.'),
            self::textResponse('fx-999 looks free to me.'),
        ];

        $report = (new JourneyRunner(
            new LlmSettings('https://1.1.1.1', 'k', 'gpt-x'),
            self::catalogFixturePath(),
            new MockHttpClient($responses),
        ))->run($journey);

        self::assertFalse($report->passed());

        $summary = $report->summary();

        // The journey id and archetype, and an overall FAIL verdict.
        self::assertStringContainsString('summary_demo', $summary);
        self::assertStringContainsString('solo', $summary);
        self::assertStringContainsString('FAIL', $summary);

        // The assertion name and its tally: 0 of 3 passed, safety, needs 3.
        self::assertMatchesRegularExpression('/✗\s+no_invented_product\s+0\/3\s+\(safety, needs 3\)/', $summary);

        // The per-run failure detail, for every one of the three runs, naming the
        // invented id — this is the line a human actually reads to know WHY it failed.
        self::assertStringContainsString(
            'run 1: the trace recorded invented product ids across the run: fx-999',
            $summary,
        );
        self::assertStringContainsString(
            'run 2: the trace recorded invented product ids across the run: fx-999',
            $summary,
        );
        self::assertStringContainsString(
            'run 3: the trace recorded invented product ids across the run: fx-999',
            $summary,
        );
    }
}
