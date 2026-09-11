<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;

/**
 * The readings an ambiguous term can have, and which of them a turn actually showed.
 *
 * Split from {@see AmbiguityNotResolvedSilently} because that class sums its branches against this
 * project's complexity gate, and because the two jobs are revised for different reasons: that class
 * knows what counts as resolving an ambiguity silently, this one knows how to read a journey's
 * configuration and a turn's cards without trusting either.
 *
 * A group is named by the FIRST element of a product's `categoryPath` — the vehicle world in the
 * `parts` catalogue. Nothing here is specific to vehicles: any catalogue whose top-level category
 * separates the competing readings of a word works the same way.
 */
final class CompetingGroups
{
    private function __construct() {}

    /**
     * The groups a journey declared, ignoring anything that is not a name.
     *
     * A journey file is hand-written PHP, so a mistyped `groups` must read as "nothing to measure
     * against" — which the caller reports as a failure — rather than fatal in the middle of a paid
     * run.
     *
     * @return list<string>
     */
    public static function named(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn(mixed $group): bool => \is_string($group)));
    }

    /**
     * Which of those groups the rendered cards came from, in the order they were rendered.
     *
     * Read off the RENDERED cards rather than the prose, for the reason every assertion in this
     * directory but two does: prose is the one thing the grounding pipeline does not control, and a
     * product the shopper was shown is a fact the server holds.
     *
     * @param list<string> $groups
     *
     * @return list<string>
     */
    public static function renderedIn(AssistantTurn $turn, array $groups): array
    {
        $found = [];

        foreach ($turn->cards as $card) {
            $world = $card->categoryPath[0] ?? null;

            if (
                \is_string($world)
                && \in_array($world, $groups, strict: true)
                && !\in_array($world, $found, strict: true)
            ) {
                $found[] = $world;
            }
        }

        return $found;
    }
}
