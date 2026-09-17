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
 * ## Counted per TURN, on that turn's first search
 *
 * See {@see FirstSearchOfTurn} for why, and for what it undercounts. The short version: the
 * assistant retries a failed search in another language, so counting searches turned three shoppers
 * into five and listed the assistant's own English guesses as if a shopper had typed them.
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
            foreach (FirstSearchOfTurn::in($trace) as $search) {
                if ($search['total'] === 0) {
                    ++$empty;
                    $emptyTerms[$search['term']] = true;
                }

                if ($search['matched'] >= self::MATCH_CAP) {
                    ++$overCap;
                    $overCapTerms[$search['term']] = true;
                }
            }
        }

        return new self($empty, $overCap, self::terms($emptyTerms), self::terms($overCapTerms));
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
