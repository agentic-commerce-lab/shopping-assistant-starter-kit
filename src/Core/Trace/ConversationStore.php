<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Persistence for conversations and the traces of their turns.
 *
 * **One table, two readers — do not build a second one** (`ARCHITECTURE.md`). The entity that exists
 * for traces is also the conversation memory, and both consumers matter for different reasons:
 *
 * - **The widget** re-hydrates from it on mount. Without that, the demo sentence
 *   *"show me the trail jersey in blue, size L"* → click through → *"add that to my cart"* cannot
 *   work, because after the page reload nothing knows what "that" is. `ARCHITECTURE.md` calls this
 *   "not optional", and it is the real reason this lands before the widget.
 * - **The merchant** reads the trace. Acceptance criterion A6 requires every turn to produce a
 *   persisted trace with all its pipeline stages.
 *
 * An interface rather than a class so the controller's tests need no database — and so the
 * in-memory implementation and the Shopware one are held to the same contract test.
 *
 * `$token` is marked `#[\SensitiveParameter]` throughout: it is a bearer credential for someone
 * else's conversation transcript, so it must not appear in a stack trace. Same treatment
 * `LlmSettings::$apiKey` already has.
 */
interface ConversationStore
{
    /**
     * Opens a conversation and returns the token the widget keeps in `sessionStorage`.
     */
    public function start(string $salesChannelId, string $locale): string;

    /**
     * Appends one turn and every event of its trace.
     *
     * The whole trace, not the last event per stage: `TraceRecorder::stages()` de-duplicates by
     * design (ruling R18), and a store built on it would drop the second tool round — which is
     * exactly where the tool-call budget failures live.
     */
    public function append(#[\SensitiveParameter] string $token, ConversationTurn $turn, TraceRecorder $trace): void;

    /**
     * The conversation so far, **oldest first**, so the widget can replay it in order.
     *
     * An unknown token yields an empty list rather than an error: a shopper with a stale
     * `sessionStorage` token must get a fresh conversation, not a 500 on page load.
     *
     * @return list<ConversationTurn>
     */
    public function history(#[\SensitiveParameter] string $token, int $limit = 20): array;

    /**
     * Every trace event recorded against this conversation, in sequence order.
     *
     * @return list<TraceEvent>
     */
    public function traceEvents(#[\SensitiveParameter] string $token): array;
}
