<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRenderer;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkReport;
use Swag\AssistantStarterKit\Command\Benchmark\CardEndpointMeasurement;
use Swag\AssistantStarterKit\Command\Benchmark\FacetProbeMeasurement;
use Swag\AssistantStarterKit\Command\Benchmark\PromptSize;
use Swag\AssistantStarterKit\Command\Benchmark\QueryMeasurement;
use Swag\AssistantStarterKit\Command\Benchmark\Timings;
use Swag\AssistantStarterKit\Command\Benchmark\VocabularyCounts;
use Swag\AssistantStarterKit\Command\Benchmark\VocabularyMeasurement;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A measurement nobody can read is not a measurement. These assertions are deliberately about
 * whether each measured number REACHES the output — the failure this guards against is a column
 * quietly dropped during a refactor, leaving a table that looks complete and is not.
 */
final class BenchmarkRendererTest extends TestCase
{
    /** @param list<QueryMeasurement> $queries */
    private static function report(bool $truncated = true, array $queries = []): BenchmarkReport
    {
        return new BenchmarkReport(
            shopLabel: 'demodata-10k',
            vocabulary: new VocabularyMeasurement(
                available: new VocabularyCounts(fields: 104, values: 5_213),
                sent: new VocabularyCounts(fields: 29, values: 29),
                truncated: $truncated,
                size: new PromptSize(vocabularyChars: 1_476, promptChars: 4_635),
            ),
            facetProbe: new FacetProbeMeasurement(liveMs: 812.5, cachedMs: 0.4),
            queries: $queries,
            cards: new CardEndpointMeasurement(ids: 12, lookups: 12, totalMs: 96.0),
        );
    }

    private static function render(BenchmarkReport $report): string
    {
        $output = new BufferedOutput();
        (new BenchmarkRenderer())->render(new SymfonyStyle(new ArrayInput([]), $output), $report);

        return $output->fetch();
    }

    public function testEveryMeasuredNumberReachesTheOutput(): void
    {
        $searchMs = new Timings();
        $searchMs->add(12.0);
        $searchMs->add(34.0);

        $rendered = self::render(self::report(queries: [new QueryMeasurement(
            'jacket',
            $searchMs,
            retrieveHits: 431,
            narrowedTo: 8,
        )]));

        foreach ([
            'demodata-10k',
            '104',
            '5213',
            '29',
            '1476',
            '4635',
            '812',
            'jacket',
            '431',
            '12',
            '96',
        ] as $expected) {
            self::assertStringContainsString($expected, $rendered, \sprintf('%s missing from output', $expected));
        }
    }

    public function testTruncationIsStatedInWordsRatherThanAsABoolean(): void
    {
        // "1" for true would be indistinguishable from a count in a table of counts.
        self::assertStringContainsString('yes', self::render(self::report(truncated: true)));
        self::assertStringContainsString('no', self::render(self::report(truncated: false)));
    }

    /** A run with no query list must still print the rest rather than blowing up on an empty table. */
    public function testARunWithNoQueriesStillRendersTheOtherSections(): void
    {
        $rendered = self::render(self::report(queries: []));

        self::assertStringContainsString('demodata-10k', $rendered);
        self::assertStringContainsString('96', $rendered);
    }
}
