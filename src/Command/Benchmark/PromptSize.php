<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Benchmark;

/**
 * How many characters the vocabulary block and the whole system prompt came to.
 *
 * Separate from {@see VocabularyCounts} because a character count is a property of the *prompt*, not
 * of the catalogue's vocabulary — `promptChars` includes every instruction that has nothing to do
 * with facets. Phase A's Finding 1 is the reason both are reported: the block's character budget is
 * what silently discarded the vocabulary, so its size is evidence rather than trivia.
 */
final class PromptSize
{
    public function __construct(
        public readonly int $vocabularyChars,
        public readonly int $promptChars,
    ) {}
}
