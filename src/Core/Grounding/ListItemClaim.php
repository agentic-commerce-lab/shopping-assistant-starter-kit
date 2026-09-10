<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * One line of a list, reduced to the claim it makes.
 *
 * Split from {@see ListedFactClaims} because finding the list and reading one of its lines are two
 * jobs revised for different reasons — that class changes when a model finds a new way to introduce
 * a list, this one when a line turns out to carry noise. It is the same seam
 * {@see SuppliedFactClaimExtractor} and {@see ClaimTokens} already sit on, and the complexity gate
 * is right that one class doing both is doing too much.
 */
final readonly class ListItemClaim
{
    /**
     * A bullet: Markdown's dash and star, the Unicode bullet, or an ordinal like `1.` / `2)`.
     *
     * Shared with {@see ListedFactClaims}, which needs the same alternation to find the block this
     * class then reads line by line — two copies would drift on the first form a model invents.
     */
    public const BULLET = '(?:[-*\x{2022}]|\d+[.)])';

    /**
     * The bullet and any Markdown emphasis go first — every measured reply bolds its item names,
     * and `**Gear` is not a word any shop's prose could assert — then the same
     * {@see ClaimPhrase::WORDS_AFTER} window the prose reader uses, for the same reason: past four
     * words the item's own noun is diluted by the rest of the line.
     */
    public static function of(string $line): string
    {
        $item = (string) preg_replace('/^[ \t]*' . self::BULLET . '[ \t]*/u', '', $line);
        $item = (string) preg_replace('/[*_`]+/u', '', $item);

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-]*/u', $item, $words);

        return ClaimPhrase::tidied(implode(' ', \array_slice($words[0] ?? [], 0, ClaimPhrase::WORDS_AFTER)));
    }
}
