<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Core\Trace\TranscriptCodec;

/**
 * An in-memory {@see ConversationStore} for tests, held to the same contract test as the Shopware
 * implementation — which is the point: a double that is merely *plausible* would let the controller
 * tests pass against behaviour the real store does not have.
 */
final class InMemoryConversationStore implements ConversationStore
{
    private readonly TranscriptCodec $codec;

    public function __construct(?TranscriptCodec $codec = null)
    {
        $this->codec = $codec ?? new TranscriptCodec();
    }

    /** @var array<string, list<array<string, mixed>>> */
    private array $turns = [];

    /** @var array<string, list<TraceEvent>> */
    private array $events = [];

    /** @var array<string, int> */
    private array $totalMs = [];

    private int $tokenCounter = 0;

    /**
     * How many conversations were opened.
     *
     * Not on {@see ConversationStore}: it exists so a test can assert that a *refused* request wrote
     * nothing at all. A throttle that stores a row before rejecting is an amplifier rather than a
     * defence, and no assertion about the response body can catch that.
     */
    public int $startedConversations = 0;

    public function start(string $salesChannelId, string $locale): string
    {
        $this->tokenCounter++;
        $this->startedConversations++;
        $token = \sprintf('%032x', $this->tokenCounter);

        $this->turns[$token] = [];
        $this->events[$token] = [];

        return $token;
    }

    public function append(#[\SensitiveParameter] string $token, ConversationTurn $turn, TraceRecorder $trace): void
    {
        // Encoded and decoded through the **same codec the DAL store uses**, rather than kept as an
        // object. Holding the object made the contract test pass by identity: it never touched
        // serialisation, so a field the real store silently dropped would still have looked stored.
        // This is the same reasoning as the seq offset below — a double that is merely plausible
        // makes the tests above it worthless.
        $this->turns[$token][] = $this->codec->encode($turn);

        // Accumulated exactly as the DAL store does. A double that skipped this would let the
        // contract test pass while the real store reported 0ms forever — which is what it did.
        $this->totalMs[$token] = ($this->totalMs[$token] ?? 0) + $trace->turnElapsedMs();

        // Offset exactly as the DAL store does: `TraceRecorder` restarts `seq` at 0 each turn, so a
        // conversation-wide ordering needs it made monotonic. A double that skipped this would let
        // the contract test pass here and fail against the real store.
        $stored = $this->events[$token] ?? [];
        $offset = $stored === [] ? 0 : max(array_map(static fn(TraceEvent $event): int => $event->seq, $stored)) + 1;

        foreach ($trace->events() as $event) {
            $this->events[$token][] = new TraceEvent(
                $offset + $event->seq,
                $event->stage,
                $event->payload,
                $event->elapsedMs,
            );
        }
    }

    public function history(#[\SensitiveParameter] string $token, int $limit = 20): array
    {
        return $this->codec->decodeAll(\array_slice($this->turns[$token] ?? [], -$limit));
    }

    public function traceEvents(#[\SensitiveParameter] string $token): array
    {
        return $this->events[$token] ?? [];
    }

    /**
     * Not on {@see ConversationStore} — the contract test reads it to assert accumulation, and the
     * DAL store's equivalent is the `total_ms` column.
     */
    public function totalMs(#[\SensitiveParameter] string $token): int
    {
        return $this->totalMs[$token] ?? 0;
    }
}
