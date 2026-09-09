<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSummary;
use Swag\AssistantStarterKit\Tests\Support\BuildsConversationRows;

/**
 * Its own file, because it guards one bug rather than the summary's behaviour in general — and
 * because {@see TraceExportSummaryTest} was at the gate's method count.
 */
final class TraceExportSummaryTurnClockTest extends TestCase
{
    use BuildsConversationRows;

    /**
     * **The regression this file did not have, and the bug it hid for a month.**
     *
     * `elapsedMs` is measured from the start of its OWN turn and restarts at zero on the next one.
     * The summary used to order a whole conversation by that field, which interleaves the turns —
     * turn two's twentieth millisecond sorted ahead of turn one's third second — and then measured
     * gaps between events belonging to different turns.
     *
     * The shape of the error is why nobody noticed: a single-reply conversation has one clock, so
     * every test above passes either way. Measured on the September 2026 export, an eleven-reply
     * conversation reported 11,554 ms of model time against roughly 65,800 ms of real waiting.
     *
     * Two turns here, each with the first test's timings. Ordered by `elapsedMs` the old walk found
     * 3,592 ms; the answer is 5,612.
     */
    public function testEachTurnIsMeasuredOnItsOwnClock(): void
    {
        $row = TraceExportSummary::of(
            self::conversation(
                events: [
                    self::event(0, 'facet.probe'),
                    self::event(22, 'prompt'),
                    self::event(3634, 'validate'),
                    self::event(3656, 'turn.end'),
                    // The second turn, and the clock starts again.
                    self::event(0, 'facet.probe'),
                    self::event(20, 'prompt'),
                    self::event(2020, 'validate'),
                    self::event(2040, 'turn.end'),
                ],
                totalMs: 5696,
            ),
            'Storefront',
        );

        self::assertSame(5696, $row['totalMs']);
        self::assertSame(5612, $row['modelMs'], 'both turns\' model waits, not one interleaved walk');
        self::assertSame(84, $row['shopMs']);
    }
}
