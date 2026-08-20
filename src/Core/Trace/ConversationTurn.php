<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * One message in a persisted conversation.
 *
 * `$cardIds` is why this class is not just `{role, text}`. The demo sentence is *"show me the trail
 * jersey in blue, size L"* → click through → *"add that to my cart"*, and after the page reload the
 * only thing that can tell the assistant what **"that"** refers to is which cards the previous turn
 * rendered. `ARCHITECTURE.md`: *"Across page loads is not optional."*
 *
 * Card ids, not cards: a stored price would be a fact frozen at write time, and by the next page
 * load it may be wrong. Every figure is re-rendered from the catalogue on read.
 */
final readonly class ConversationTurn
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @param list<string>                $cardIds
     * @param array<string, list<string>> $warnings the grounding warnings this turn produced —
     *        `unbackedPrices` and `unbackedAvailabilityClaims`. Stored with the turn because a
     *        re-hydrated conversation must not lose the correction: without it, the misleading
     *        sentence comes back unannotated on every page load, which is the most shopper-visible
     *        defect in this product reappearing for free.
     *
     * `$createdAt` is nullable because rows written before this field existed have no time, and a
     * shopper holding an older conversation token must get a message with no timestamp rather than
     * an exception — or worse, a fabricated one.
     *
     * @mago-expect lint:excessive-parameter-list
     * A `final readonly` value object whose call sites all use named arguments; carve-out 1 in the
     * standing constraints. Splitting a conversation turn in two to satisfy a count would be the
     * worse code.
     */
    public function __construct(
        public string $role,
        public string $prose,
        public array $cardIds = [],
        public string $outcome = '',
        public ?\DateTimeImmutable $createdAt = null,
        public array $warnings = [],
    ) {}
}
