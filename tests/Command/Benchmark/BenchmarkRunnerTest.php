<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\BenchmarkRunner;
use Swag\AssistantStarterKit\Command\Benchmark\CountingGateway;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;

/**
 * The runner is where every number comes from, so this is where the SHAPE of the measurement is
 * pinned down. Deliberately no assertion on any duration: a millisecond figure from a fixture
 * gateway describes this machine's mood, and the spec is explicit that a latency is not a pass.
 *
 * What IS asserted: that every field is populated, that repetitions produce that many samples, that
 * the cards count equals one lookup per id, and that nothing writes.
 */
final class BenchmarkRunnerTest extends TestCase
{
    /** Simple products: the fixture gateway returns null for a parent with variants. */
    private const SIMPLE_PRODUCT_IDS = ['fx-001', 'fx-007', 'fx-008'];

    private static function gateway(): CountingGateway
    {
        return new CountingGateway(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'));
    }

    public function testItMeasuresEveryQueryTheGivenNumberOfTimes(): void
    {
        $report = (new BenchmarkRunner(self::gateway()))->run(
            'fixture',
            new AssistantConfig(),
            ['Trail Jersey', 'bottle'],
            repetitions: 4,
            cardIds: [],
        );

        self::assertCount(2, $report->queries);

        foreach ($report->queries as $query) {
            self::assertSame(4, $query->searchMs->count());
        }
    }

    public function testItReportsBothTheAvailableAndTheSentVocabulary(): void
    {
        $report = (new BenchmarkRunner(self::gateway()))->run(
            'fixture',
            new AssistantConfig(),
            ['bottle'],
            repetitions: 1,
            cardIds: [],
        );

        // The small fixture fits, so nothing is cut and the two counts agree — which is exactly what
        // makes a disagreement on a real shop meaningful.
        self::assertGreaterThan(0, $report->vocabulary->available->fields);
        self::assertSame($report->vocabulary->available->fields, $report->vocabulary->sent->fields);
        self::assertFalse($report->vocabulary->truncated);
        self::assertGreaterThan(
            $report->vocabulary->size->vocabularyChars,
            $report->vocabulary->size->promptChars,
            'the prompt is the vocabulary block plus every instruction around it',
        );
    }

    public function testTheCardsRowIsOneLookupPerRequestedId(): void
    {
        $report = (new BenchmarkRunner(self::gateway()))->run(
            'fixture',
            new AssistantConfig(),
            [],
            repetitions: 1,
            cardIds: self::SIMPLE_PRODUCT_IDS,
        );

        self::assertSame(3, $report->cards->ids);
        self::assertSame(3, $report->cards->lookups, 'the N+1 is the measurement, not an accident');
    }

    public function testItNeverWritesToTheShop(): void
    {
        $gateway = self::gateway();

        (new BenchmarkRunner($gateway))->run(
            'fixture',
            new AssistantConfig(),
            ['Trail Jersey'],
            repetitions: 2,
            cardIds: ['fx-001'],
        );

        // The guarantee that makes this safe to point at any shop, including one nobody snapshotted.
        self::assertSame(0, $gateway->callsTo('addToCart'));
        self::assertSame(0, $gateway->callsTo('cart'));
    }

    /** The facet probe is measured twice on purpose, so the cache path is exercised, not skipped. */
    public function testTheFacetProbeIsMeasuredColdAndWarm(): void
    {
        $gateway = self::gateway();

        $report = (new BenchmarkRunner($gateway))->run(
            'fixture',
            new AssistantConfig(),
            [],
            repetitions: 1,
            cardIds: [],
        );

        // Live once, instance-cache once (no gateway), then the shared-tier probe with no pool
        // wired — which must go live again. Plus the vocabulary measurement's own facets() call.
        self::assertSame(3, $gateway->callsTo('facets'));
        self::assertGreaterThanOrEqual(0.0, $report->facetProbe->liveMs);
        self::assertGreaterThanOrEqual(0.0, $report->facetProbe->cachedMs);
        self::assertFalse($report->facetProbe->sharedHit, 'no pool wired means no shared hit');
    }
}
