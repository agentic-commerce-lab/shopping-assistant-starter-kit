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
     * @param list<string> $cardIds
     */
    public function __construct(
        public string $role,
        public string $prose,
        public array $cardIds = [],
        public string $outcome = '',
    ) {}
}
