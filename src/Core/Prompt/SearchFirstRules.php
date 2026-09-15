<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

/**
 * The rule that makes a model look something up before it asks a question.
 *
 * Its own class rather than a constant on {@see SystemPrompt}, for the reason {@see DepartmentRules},
 * {@see DocumentRules} and {@see PropertyRules} were split off it: that file sits on this project's
 * ~400-line gate, and this was the largest constant still on it that nothing else in the file refers
 * to. Moving it changed no behaviour — {@see SystemPrompt::build()} appends it under exactly the
 * condition it always did.
 */
final class SearchFirstRules
{
    private function __construct() {}

    /**
     * Added only when no product is already in context — see {@see self::build()}.
     *
     * **Two sentences, and both state what the models that work already do.** Measured 2026-09-02:
     * `google/gemini-3.7-flash` renders products and does not re-ask what the shopper said, while
     * `openai/gpt-5-mini` asked three confirming questions in a row about a size named in the first
     * message ("Would you like me to check whether it comes in size M?") and, in two of three runs
     * of `fashion_many_matches`, answered a product question with `toolCalls: 0` — no lookup at all.
     * A rule a model already satisfies cannot move it by being written down; a model that does not
     * satisfy it was missing the instruction.
     *
     * **The first sentence exists in the tool description already** — *"never reply with a question
     * and no products"* — and that was not enough. A function description is read when the model is
     * already considering the function; a model deciding whether to look anything up at all has not
     * got that far. So it moves up here, where the decision is made.
     *
     * **The second is the one aimed at the confirmations.** The rules block says "Ask one question
     * at a time, and only when the answer would change what you recommend", which is a permission,
     * and a cautious model reads a permission as an invitation. This says what the boundary is:
     * repeating the shopper's own words back as a question changes nothing.
     */
    public const SEARCH_BEFORE_ASKING = <<<'PROMPT'
        Search before you answer. If the message is about products at all, call a tool and answer
        from what comes back — never reply with only a question when you have not looked anything up.

        And act on what the shopper already told you. If they named a garment, a colour, a size, a
        budget or an occasion, search for it; do not ask them to confirm it, do not offer to look it
        up, and do not ask which of two things they meant when they said one of them. A question is
        worth asking only when the answer would change what you recommend — repeating their own
        words back is not.
        PROMPT;
}
