<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

/**
 * How much of one short text the other one already said.
 *
 * Split from {@see RepeatedAsk} the same way {@see \Swag\AssistantStarterKit\Core\Grounding\PropertyMention}
 * is split from `PropertyNegation`: walking a conversation's history and comparing two strings are
 * two jobs that change for different reasons. This file changes when the measure is wrong; that one
 * changes when what counts as "before" is wrong.
 *
 * ## The overlap coefficient, not Jaccard
 *
 * Shared words over the SHORTER set. Jaccard was the first attempt and it could only see a verbatim
 * retype: "welche sättel gibt es?" against "was gibt es für sättel?" scores 0.4 by Jaccard and 0.67
 * this way, because dividing by the union punishes the second ask for being phrased differently
 * rather than for being about something else. Asks are short and unequal, which is the case this
 * coefficient exists for.
 *
 * ## Word sets, not sequences
 *
 * "do you have helmets in M" and "helmets in M?" share every word that matters and no useful order.
 * Sets are also cheap, which matters on a path that runs before every model call.
 */
final class WordOverlap
{
    /**
     * Words too short or too common to carry a topic. Kept deliberately tiny and language-mixed: a
     * real stop-word list per language is a dependency, and the threshold in {@see RepeatedAsk} is
     * doing the actual work.
     *
     * @var list<string>
     */
    private const NOISE = [
        'the',
        'a',
        'an',
        'is',
        'it',
        'do',
        'you',
        'i',
        'me',
        'my',
        'and',
        'or',
        'der',
        'die',
        'das',
        'ich',
        'du',
        'ist',
        'es',
        'und',
        'oder',
        'ein',
        'eine',
    ];

    /** Below this a word is punctuation's neighbour rather than a topic. */
    private const MIN_LENGTH = 3;

    private function __construct() {}

    /**
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return float 0.0 when either side has nothing to compare
     */
    public static function of(array $left, array $right): float
    {
        $shared = \count(array_intersect($left, $right));

        // `max(1, ...)` rather than a zero guard: an empty side shares nothing, so the numerator is
        // already zero and the division is only there to avoid a fatal.
        return $shared / max(1, min(\count($left), \count($right)));
    }

    /**
     * The topic words of one message, deduplicated.
     *
     * @return list<string>
     */
    public static function words(string $message): array
    {
        // `?: []` rather than a branch: false here is a compile failure on a constant pattern, which
        // cannot happen, and a message with no words is the empty-set case the caller handles.
        $split = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($message), flags: \PREG_SPLIT_NO_EMPTY) ?: [];

        $long = array_filter($split, static fn(string $word): bool => mb_strlen($word) >= self::MIN_LENGTH);

        return array_values(array_unique(array_diff($long, self::NOISE)));
    }
}
