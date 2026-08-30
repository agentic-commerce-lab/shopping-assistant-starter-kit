<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

use Swag\AssistantStarterKit\Core\Context\ShoppingContext;

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
 *
 * **Scoping, added here rather than in the controller.** Presenting a token was never proof of
 * ownership — the primary key is a UUIDv7 (~74 bits of unpredictable material, not the 128 bits a
 * fresh random secret would carry), not a permission. See {@see ShoppingContext}'s own docblock for
 * the full accounting of what the token actually is and where it leaks. Every method but
 * {@see self::traceEvents()} now also takes the shopper's {@see ShoppingContext} and compares it
 * against the scope the conversation was opened under. `history()` and `append()` disagree on what
 * a mismatch does, deliberately: see their own docblocks.
 */
interface ConversationStore
{
    /**
     * Opens a conversation and returns the token the widget keeps in `sessionStorage`.
     *
     * `$context` is recorded **once, here**: a guest who logs in mid-conversation stays a guest on
     * it, because the column answers "who produced this trace" and re-attributing would make it
     * answer "who was last seen" — a different, and less useful, question. Every later call to
     * `history()` or `append()` compares its own context against what was written here.
     */
    public function start(ShoppingContext $context, string $locale): string;

    /**
     * Appends one turn and every event of its trace.
     *
     * The whole trace, not the last event per stage: `TraceRecorder::stages()` de-duplicates by
     * design (ruling R18), and a store built on it would drop the second tool round — which is
     * exactly where the tool-call budget failures live.
     *
     * @throws ForeignConversationException if `$context` does not match the scope the conversation
     *                                       was opened under. This is a backstop, not a shopper-facing
     *                                       path: the controller validates ownership before ever
     *                                       calling this, so reaching the throw means that validation
     *                                       was skipped. Failing loudly here is safer than silently
     *                                       dropping a shopper's turn.
     */
    public function append(
        #[\SensitiveParameter]
        string $token,
        ShoppingContext $context,
        ConversationTurn $turn,
        TraceRecorder $trace,
    ): void;

    /**
     * The conversation so far, **oldest first**, so the widget can replay it in order.
     *
     * An unknown token yields an empty list rather than an error: a shopper with a stale
     * `sessionStorage` token must get a fresh conversation, not a 500 on page load. A token that
     * belongs to a *different* scope yields the same empty list, for the same reason and to avoid
     * disclosing which case it was: a shopper-facing read must never say why it returned nothing.
     *
     * @return list<ConversationTurn>
     */
    public function history(#[\SensitiveParameter] string $token, ShoppingContext $context, int $limit = 20): array;

    /**
     * Every trace event recorded against this conversation, in sequence order.
     *
     * Unscoped — and, as of this writing, that is not load-bearing for anything in production: the
     * merchant export does not call this method. It goes through
     * {@see \Swag\AssistantStarterKit\Controller\AssistantTraceExportController} →
     * {@see \Swag\AssistantStarterKit\Core\Trace\Export\DalTraceExportSource::load()} → the `events`
     * association, reading the table directly instead. This method has no caller outside the test
     * suite today; it is a test-only affordance for exercising the contract, not something scoping it
     * would break.
     *
     * Known defect in {@see DalConversationStore}'s implementation, left unfixed here as production
     * behaviour and out of scope for this branch: it does not read `elapsed_ms` back from the row, so
     * every event it returns reports `0` regardless of what was stored — which is why
     * `ConversationStoreContractTest`'s elapsed-offset case runs only against `InMemoryConversationStore`.
     *
     * @return list<TraceEvent>
     */
    public function traceEvents(#[\SensitiveParameter] string $token): array;
}
