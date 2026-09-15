<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;

/**
 * The readings an ambiguous term can have, and which of them a turn actually rendered.
 *
 * Split from {@see AmbiguityNotResolvedSilently} because that class sums its branches against this
 * project's complexity gate, and because the two jobs are revised for different reasons: that class
 * knows what counts as resolving an ambiguity silently, this one knows how to read a journey's
 * configuration and a turn's output without trusting either.
 *
 * A group is named by the FIRST element of a product's `categoryPath` — the vehicle world in the
 * `parts` catalogue — and carries its own short list of words a reply would use for it. Nothing
 * here is specific to vehicles: any catalogue whose top-level category separates the competing
 * readings of a word works the same way.
 *
 * The other half — what the reply SAYS about those readings — is {@see GroupVocabulary}, split off
 * at the same gate. Structure here, prose there.
 */
final class CompetingGroups
{
    private function __construct() {}

    /**
     * The groups a journey declared, as group name => words that name it in prose.
     *
     * A journey file is hand-written PHP, so anything that is not a name with a word list is
     * dropped — the caller reports "nothing to measure against" rather than failing mid-run.
     *
     * @return array<string, list<string>>
     */
    public static function named(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $groups = [];

        foreach ($raw as $name => $words) {
            if (\is_string($name) && \is_array($words)) {
                $groups[$name] = array_values(array_filter($words, static fn(mixed $w): bool => \is_string($w)));
            }
        }

        return $groups;
    }

    /**
     * Which of those groups the rendered cards came from, in the order they were rendered.
     *
     * Read off the RENDERED cards rather than the prose, for the reason every assertion in this
     * directory but two does: prose is the one thing the grounding pipeline does not control, and a
     * product the shopper was shown is a fact the server holds.
     *
     * @param array<string, list<string>> $groups
     *
     * @return list<string>
     */
    public static function renderedIn(AssistantTurn $turn, array $groups): array
    {
        $found = [];

        foreach ($turn->cards as $card) {
            $world = $card->categoryPath[0] ?? null;

            if (\is_string($world) && isset($groups[$world]) && !\in_array($world, $found, strict: true)) {
                $found[] = $world;
            }
        }

        return $found;
    }
}
