<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * How a conversation's `total_ms` accumulates.
 *
 * Separate from {@see ConversationStoreContractTest} because it reads an accessor that only the
 * double exposes — the DAL store's equivalent is the `total_ms` column, and there is no integration
 * harness to read it back (cut in v0).
 *
 * **Why this file exists at all:** `total_ms` was written as a literal `0` by
 * `DalConversationStore::start()` and never updated again. Every conversation in the test shop
 * reported 0ms while real turns took eight seconds. The column existed, its docblock claimed it was
 * "measured with a wall clock around the runner call, so it is honest", and nothing ever read it
 * back — which is exactly the always-zero column ruling R62 refused to add, already in the schema
 * and already lying. Found by opening the Administration trace view on a real turn.
 */
final class ConversationTotalMsTest extends TestCase
{
    use GuestShoppingContextFixture;

    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testTurnDurationsAccumulateAcrossTurns(): void
    {
        $store = new InMemoryConversationStore();
        $token = $store->start($this->guest(), 'en-GB');

        foreach ([8_132, 4_500] as $turnMs) {
            $store->append(
                $token,
                $this->guest(),
                new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'ok'),
                self::traceLasting($turnMs),
            );
        }

        self::assertSame(12_632, $store->totalMs($token));
    }

    public function testATurnThatRecordedNothingAddsNothing(): void
    {
        // The controller appends the shopper's own turn with an empty `new TraceRecorder()`. If
        // that contributed, every total would drift upward by a stray offset.
        $store = new InMemoryConversationStore();
        $token = $store->start($this->guest(), 'en-GB');

        $store->append(
            $token,
            $this->guest(),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'hello'),
            new TraceRecorder(),
        );

        self::assertSame(0, $store->totalMs($token));
    }

    public function testTheTotalMatchesTheLastEventOffsetForASingleTurn(): void
    {
        // The invariant the Administration depends on: the summary card's duration and the last row
        // of the timeline must agree, because they are the same number by construction.
        $store = new InMemoryConversationStore();
        $token = $store->start($this->guest(), 'en-GB');

        $trace = self::traceLasting(8_183);
        $store->append(
            $token,
            $this->guest(),
            new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'ok'),
            $trace,
        );

        $events = $store->traceEvents($token);
        $last = $events[array_key_last($events)] ?? null;

        self::assertNotNull($last);
        self::assertSame($last->elapsedMs, $store->totalMs($token));
    }

    private static function traceLasting(int $ms): TraceRecorder
    {
        $now = 0;
        $clock = static function () use (&$now): int {
            return $now;
        };

        $trace = new TraceRecorder($clock);
        $now = $ms * 1_000_000;
        $trace->record('turn.end', []);

        return $trace;
    }
}
