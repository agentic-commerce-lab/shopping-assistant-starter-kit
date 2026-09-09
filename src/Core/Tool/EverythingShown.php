<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Whether this reply is the whole of what the search matched — the one condition under which the
 * assistant may tell a shopper there is nothing more.
 *
 * ## Why the tool decides and not the model
 *
 * A shopper who asks for more and gets the same products back is owed a plain "these are all there
 * are". Session `07b4c0f7` is what happens without it: three "more please" turns, the same two
 * lights each time, presented as new. The prompt now permits that sentence — and a permission with
 * no condition on it is how one untrue answer replaces another.
 *
 * The condition is decidable here and nowhere else, because it needs **both** of two facts:
 *
 * 1. **No product is being withheld.** {@see WithheldCount} counts distinct products rather than
 *    rows, so a family whose variants were truncated does not count as something more to show.
 * 2. **The candidate window did not fill up.** `matched` is a floor rather than a census whenever it
 *    did (ruling T4) — there may be whole families beyond the 50-product window that retrieval never
 *    saw. `more: true` says exactly that, and it is the case this class exists to refuse.
 *
 * Asking the model to combine two fields it must first interpret correctly is the kind of
 * arithmetic the September 2026 review measured it failing: `total` and `matched` were both in the
 * reply and three turns still presented a fraction of a result set as the whole of it. So the shop
 * computes the fact, in the idiom this file already uses for
 * {@see SearchProductsTool::NO_MATCH_NOTE}, {@see TruncatedFamilies} and {@see TermContribution} —
 * the reply states what it does and does not contain, rather than leaving it to be derived.
 *
 * ## What it deliberately does not claim
 *
 * Not that the shop has none of a kind of product. An exhausted search establishes only that these
 * words matched nothing further, which is the distinction `NO_MATCH_NOTE` exists to protect and
 * which the note below repeats rather than weakens.
 */
final class EverythingShown
{
    /**
     * What the model is told, and it is scoped to the search on purpose.
     *
     * "of what this search matched", never "in the shop": the second is a claim about the
     * assortment that no search result can support.
     */
    public const NOTE =
        'This reply contains every product this search matched, so you may say plainly that there '
            . 'are no more of these to show. Say it about THIS SEARCH and never about the shop: other '
            . 'words may still match products this one did not.';

    private function __construct() {}

    /**
     * @param bool $withholding whether {@see WithheldCount} reported anything held back
     * @param bool $saturated   whether the candidate window filled up, i.e. `more`
     *
     * @return array{all_shown?: true, all_shown_note?: string} spread into the tool result
     */
    public static function replyFor(bool $withholding, bool $saturated): array
    {
        if ($withholding || $saturated) {
            return [];
        }

        return ['all_shown' => true, 'all_shown_note' => self::NOTE];
    }
}
