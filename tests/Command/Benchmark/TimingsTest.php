<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Benchmark;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Benchmark\Timings;

/**
 * The one piece of this command with a right answer, so it is the one piece with real assertions.
 * Everything else it measures is a duration, and a duration is not a pass (spec, *Phase B in
 * outline*) — which makes getting the arithmetic provably right the only correctness this command
 * can offer.
 */
final class TimingsTest extends TestCase
{
    public function testPercentilesUseNearestRankOverASortedSample(): void
    {
        $timings = new Timings();

        // Added out of order on purpose: a percentile that depended on insertion order would be
        // wrong in exactly the way a benchmark never reveals, because the numbers still look
        // plausible.
        foreach ([50, 10, 100, 30, 90, 20, 80, 40, 70, 60] as $sample) {
            $timings->add((float) $sample);
        }

        self::assertSame(10, $timings->count());
        self::assertSame(50.0, $timings->p50());
        self::assertSame(100.0, $timings->p95());
        self::assertSame(100.0, $timings->max());
        self::assertSame(55.0, $timings->mean());
    }

    public function testP95OfTwentySamplesIsTheNineteenthSmallest(): void
    {
        $timings = new Timings();

        for ($i = 1; $i <= 20; ++$i) {
            $timings->add((float) $i);
        }

        // ceil(0.95 * 20) = 19, so the 19th smallest. Stated as a test because "which of the two
        // neighbouring samples is p95" is the part of percentile arithmetic people get wrong.
        self::assertSame(19.0, $timings->p95());
        self::assertSame(10.0, $timings->p50());
    }

    public function testASingleSampleIsEveryPercentile(): void
    {
        $timings = new Timings();
        $timings->add(7.5);

        self::assertSame(7.5, $timings->p50());
        self::assertSame(7.5, $timings->p95());
        self::assertSame(7.5, $timings->mean());
    }

    /** An empty run must report zero rather than divide by it. */
    public function testNoSamplesReportsZeroRatherThanFailing(): void
    {
        $timings = new Timings();

        self::assertSame(0, $timings->count());
        self::assertSame(0.0, $timings->p50());
        self::assertSame(0.0, $timings->p95());
        self::assertSame(0.0, $timings->mean());
        self::assertSame(0.0, $timings->max());
    }

    public function testAFractionOutsideZeroToOneIsRejected(): void
    {
        $timings = new Timings();
        $timings->add(1.0);

        $this->expectException(\InvalidArgumentException::class);

        $timings->percentile(1.5);
    }
}
