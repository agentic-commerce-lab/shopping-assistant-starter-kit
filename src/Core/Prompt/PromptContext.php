<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The "you already know about these" half of a turn's prompt.
 *
 * Moved out of `AssistantAgentFactory` when that file reached this project's 400-line cap. It
 * belongs here rather than there on its own merits: it composes {@see ViewingContext} and
 * {@see RecentCardsContext}, both of which live in this namespace, and it holds nothing the factory
 * owns — no gateway, no trace, no renderer.
 */
final class PromptContext
{
    private function __construct() {}

    /**
     * The two "you already know about these" clauses, as one block for {@see SystemPrompt::build()}.
     *
     * Concatenated rather than given their own prompt parameter: `build()` appends this string after
     * the rules and the vocabulary, and both clauses belong in exactly that position. A second
     * parameter would have to be threaded through `Bundle` and every caller to say the same thing.
     *
     * Either half may be empty — most turns have no page product, and the first turn of a
     * conversation has no previous reply — so the blank line between them is only written when both
     * are actually present.
     *
     * @param array<string, list<string>> $familyOptions
     * @param list<ProductCard>           $recentCards
     */
    public static function of(?ProductCard $viewing, array $familyOptions, array $recentCards): string
    {
        $clauses = array_filter(
            [
                ViewingContext::line($viewing, $familyOptions),
                RecentCardsContext::line($recentCards),
            ],
            static fn(string $clause): bool => $clause !== '',
        );

        return implode("\n\n", $clauses);
    }
}
