<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Turns where the shopper's search found nothing, and turns where it found more than the shop will
 * put a number on.
 *
 * Two merchant actions, in opposite directions. Nothing found means the catalogue lacks the product
 * or the words for it, and the words are listed. Too much found means the shopper was handed a
 * category rather than an answer, and the filters are too coarse to narrow it.
 *
 * ## Counted per TURN, on what the turn achieved
 *
 * A turn counts as a gap only when **none** of its searches returned anything — because that is the
 * question a merchant is asking: did this shopper get products or not? The previous rule judged a
 * turn by its first search alone, and published `Reifen 28` as a catalogue gap in a shop holding
 * over a hundred 28-inch tyres: that turn's first search missed, its retry returned five, and the
 * shopper saw three cards. Measured 2026-09-17.
 *
 * **The word shown is the word that caused the outcome being reported.** For a gap that is the
 * turn's first term, which is the one built from the shopper's sentence. For a turn over the cap it
 * is the search that actually hit the cap — in the example above `Reifen`, not `Reifen 28`, which
 * returned nothing and would have explained nothing.
 *
 * {@see SearchesByTurn} carries the two real event shapes this rests on.
 *
 * ## The cap is read off `matched`, not off the `many` flag — and that reverses an earlier decision
 *
 * `SearchResultCounts` sets `many: true` when its exact match count reports itself capped, and
 * reading that flag was the obvious choice: it is the shop's own statement rather than a threshold
 * of ours. **Measured on 2026-09-17 over 257 real `tool.result` events in a 118 232-product shop:
 * the flag appears 0 times, while 109 of those events carry `matched >= 100`.** The flag only
 * appears on the path that computes an exact count, and that path did not run once. So the metric
 * read a signal that never fires and reported zero for 42 % of searches.
 *
 * {@see self::MATCH_CAP} is therefore read directly. It duplicates a number that lives in
 * `SearchResultCounts`, which is the cost; the alternative was a metric that is structurally always
 * zero, which is worse than duplication because it reads as good news.
 */
final readonly class SearchOutcomes
{
    public const MAX_TERMS = 25;

    /**
     * The point past which the shop stops putting a figure behind a match count.
     *
     * Mirrors the cap in {@see \Swag\AssistantStarterKit\Core\Tool\SearchResultCounts}, which does
     * not expose it as a constant. If that cap moves, this has to move with it — and a test over a
     * real export is what would notice, since nothing connects the two in code.
     */
    public const MATCH_CAP = 100;

    /**
     * @param list<string> $emptyTerms
     * @param list<string> $overCapTerms
     */
    private function __construct(
        public int $turnsFoundNothing,
        public int $turnsOverCap,
        public array $emptyTerms,
        public array $overCapTerms,
    ) {}

    /** @param list<ConversationTrace> $traces */
    public static function of(array $traces): self
    {
        $empty = 0;
        $overCap = 0;
        $emptyTerms = [];
        $overCapTerms = [];

        foreach ($traces as $trace) {
            foreach (SearchesByTurn::in($trace) as $searches) {
                $gapTerm = self::gapTermOf($searches);

                if ($gapTerm !== null) {
                    ++$empty;
                    $emptyTerms[$gapTerm] = true;
                }

                $capTerm = self::capTermOf($searches);

                if ($capTerm !== null) {
                    ++$overCap;
                    $overCapTerms[$capTerm] = true;
                }
            }
        }

        return new self($empty, $overCap, self::terms($emptyTerms), self::terms($overCapTerms));
    }

    /**
     * The word to publish when a turn gave the shopper nothing, or null when it gave them something.
     *
     * The FIRST term, because that is the query built from the shopper's own sentence; everything
     * after it is the assistant reacting to its own miss, in its own words, sometimes in another
     * language.
     *
     * @param list<array{term: string, total: int, matched: int}> $searches
     */
    private static function gapTermOf(array $searches): ?string
    {
        foreach ($searches as $search) {
            if ($search['total'] > 0) {
                return null;
            }
        }

        return $searches[0]['term'] ?? null;
    }

    /**
     * The word to publish when a turn hit the match cap, or null when it did not.
     *
     * **The search that hit it**, not the turn's first. A turn whose opening query returned nothing
     * and whose retry matched four hundred products is over the cap because of the retry, and
     * naming the opening query would put a word in that list which explains none of it.
     *
     * @param list<array{term: string, total: int, matched: int}> $searches
     */
    private static function capTermOf(array $searches): ?string
    {
        foreach ($searches as $search) {
            if ($search['matched'] >= self::MATCH_CAP) {
                return $search['term'];
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seen
     *
     * @return list<string>
     */
    private static function terms(array $seen): array
    {
        // The empty key is dropped rather than rendered: a count without a term is still a count,
        // but a blank row in the administration is something a merchant cannot act on.
        unset($seen['']);

        return array_values(\array_slice(array_keys($seen), 0, self::MAX_TERMS));
    }
}
