<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

/**
 * A match count, and whether it is the whole truth or the point where counting stopped.
 *
 * {@see ExactMatchCount} used to answer with a bare `?int`, which could say "42" or "I could not
 * count". It now needs a third answer — "more than I bothered to count" — and a bare int cannot
 * carry it: `100` would be indistinguishable from a cap of 100 reached.
 *
 * The distinction is what the shopper hears. Below the cap the assistant may say *"there are 42
 * more"*, which is a fact worth having. At the cap it must say *"there are very many"* and offer to
 * narrow, because a number it does not actually know is worse than an honest qualifier — and
 * *"4.812 more"* would not have meant anything different to the reader anyway.
 */
final readonly class MatchCount
{
    private function __construct(
        public int $count,
        public bool $capped,
    ) {}

    /** A number the gateway counted all the way to. */
    public static function exact(int $count): self
    {
        return new self($count, capped: false);
    }

    /** Counting stopped here; the true number is this or larger. */
    public static function atLeast(int $cap): self
    {
        return new self($cap, capped: true);
    }
}
