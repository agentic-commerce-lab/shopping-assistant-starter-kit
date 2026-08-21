<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class TraceRecorderTest extends TestCase
{
    public function testRecordsStagesInOrderWithSequenceNumbers(): void
    {
        $recorder = new TraceRecorder();
        $recorder->record('understand', ['intent' => 'discovery']);
        $recorder->record('retrieve', ['hits' => 6]);

        $events = $recorder->events();

        self::assertCount(2, $events);
        self::assertSame(0, $events[0]?->seq);
        self::assertSame('retrieve', $events[1]?->stage);
        self::assertSame(['understand', 'retrieve'], $recorder->stages());
    }

    public function testPayloadReturnsTheLastPayloadForAStage(): void
    {
        $recorder = new TraceRecorder();
        $recorder->record('retrieve', ['hits' => 1]);
        $recorder->record('retrieve', ['hits' => 9]);

        self::assertSame(['hits' => 9], $recorder->payload('retrieve'));
        self::assertNull($recorder->payload('render'));
    }

    public function testStagesDeduplicate(): void
    {
        $recorder = new TraceRecorder();
        $recorder->record('retrieve', ['hits' => 1]);
        $recorder->record('rank', ['score' => 0.9]);
        $recorder->record('retrieve', ['hits' => 9]);

        // stages() de-duplicates: returns 'retrieve' once, not twice
        self::assertSame(['retrieve', 'rank'], $recorder->stages());

        // events() still contains both 'retrieve' events, in order
        $events = $recorder->events();
        self::assertCount(3, $events);
        self::assertSame('retrieve', $events[0]?->stage);
        self::assertSame('rank', $events[1]?->stage);
        self::assertSame('retrieve', $events[2]?->stage);

        // payload() returns the last payload for 'retrieve'
        self::assertSame(['hits' => 9], $recorder->payload('retrieve'));
    }

    public function testStampsEachEventWithMillisecondsSinceConstruction(): void
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $recorder = new TraceRecorder($clock);

        $now = 120_000_000; // +120ms
        $recorder->record('understand', ['intent' => 'discovery']);

        $now = 2_400_000_000; // +2400ms
        $recorder->record('retrieve', ['hits' => 6]);

        $events = $recorder->events();

        self::assertSame(120, $events[0]?->elapsedMs);
        self::assertSame(2400, $events[1]?->elapsedMs);
    }

    public function testElapsedIsRelativeToTheRecorderSoEachTurnRestartsAtZero(): void
    {
        // `TraceRecorder` is built fresh per turn (AssistantAgentFactory, AssistantController),
        // which is what makes "since construction" mean "since turn start". A recorder built at
        // an arbitrary clock value must still report offsets, never absolute time.
        $now = 9_000_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $recorder = new TraceRecorder($clock);
        $now = 9_050_000_000;
        $recorder->record('understand', []);

        self::assertSame(50, $recorder->events()[0]?->elapsedMs);
    }

    public function testElapsedNeverGoesBackwardsWithinATurn(): void
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $recorder = new TraceRecorder($clock);

        foreach ([10, 400, 15_800] as $ms) {
            $now = $ms * 1_000_000;
            $recorder->record('stage', []);
        }

        $elapsed = array_map(static fn(TraceEvent $event): int => $event->elapsedMs, $recorder->events());

        self::assertSame([10, 400, 15_800], $elapsed);
    }
}
