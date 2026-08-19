<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
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
}
