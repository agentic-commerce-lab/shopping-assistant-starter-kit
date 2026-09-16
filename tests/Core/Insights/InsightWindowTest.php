<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\InsightWindow;

final class InsightWindowTest extends TestCase
{
    public function testTheFirstRunEverLooksBackOneDay(): void
    {
        $now = new \DateTimeImmutable('2026-09-16 03:00:00');

        $window = InsightWindow::next(null, $now);

        self::assertSame('2026-09-15 03:00:00', $window->start->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-16 03:00:00', $window->end->format('Y-m-d H:i:s'));
    }

    public function testTheNextWindowStartsWhereTheLastOneEnded(): void
    {
        // No gap and no overlap: a conversation must be counted exactly once, ever. A gap loses a
        // night's findings silently; an overlap double-counts a funnel a merchant reads as revenue.
        $lastEnd = new \DateTimeImmutable('2026-09-15 03:00:00');
        $now = new \DateTimeImmutable('2026-09-16 03:00:00');

        $window = InsightWindow::next($lastEnd, $now);

        self::assertEquals($lastEnd, $window->start);
        self::assertEquals($now, $window->end);
    }

    public function testAWindowThatWouldRunBackwardsIsEmptyRatherThanInverted(): void
    {
        // Reachable by a clock change or by the task running twice. An inverted interval selects
        // every conversation or none depending on which way the comparison is written, and neither
        // is a night's work.
        $lastEnd = new \DateTimeImmutable('2026-09-16 04:00:00');
        $now = new \DateTimeImmutable('2026-09-16 03:00:00');

        $window = InsightWindow::next($lastEnd, $now);

        self::assertEquals($window->start, $window->end);
        self::assertTrue($window->isEmpty());
    }

    public function testAWindowWithTimeInItIsNotEmpty(): void
    {
        $window = InsightWindow::next(
            new \DateTimeImmutable('2026-09-15 03:00:00'),
            new \DateTimeImmutable('2026-09-16 03:00:00'),
        );

        self::assertFalse($window->isEmpty());
    }
}
