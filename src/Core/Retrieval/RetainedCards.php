<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The post-steps every card list {@see RetrievalPass} can return gets, whichever of its four reads
 * produced it: ordered as asked, fragment matches removed, the stated budget enforced.
 *
 * ## Why it is its own class
 *
 * It was two private methods on {@see RetrievalPass} until 2026-09-03, when that class learned a
 * fourth read — the relaxed term with the option filters dropped — and mago's per-class cyclomatic
 * budget said no. The boundary is the right one either way: everything here is about **one card
 * list**, and everything left in `RetrievalPass` is about which read to try next.
 *
 * ## Applied to every list, deliberately
 *
 * The retries relax options and words, never the shopper's budget, so a card that broke the ceiling
 * on the first read still breaks it on the fourth. Same for a fragment match: relaxing a term does
 * not make "indiCATor" a helmet.
 *
 * ## `narrowed` means one specific thing
 *
 * *The SQL price range disagreed with the shopper's own prices.* {@see ExactMatchCount} reads it to
 * decide whether it may trust a count, so it is set — never cleared — whenever the budget drops
 * something, even if a later read happens not to. A dropped fragment match deliberately does NOT set
 * it: that is a relevance judgement and tells a counter nothing.
 */
final readonly class RetainedCards
{
    /**
     * @param list<ProductCard> $cards
     * @param bool              $budgetNarrowed see the class docblock — the price range disagreed,
     *                                          not "something was removed"
     */
    private function __construct(
        public array $cards,
        public bool $budgetNarrowed,
    ) {}

    /**
     * @param list<ProductCard> $cards
     * @param ?string           $term  the words the gateway actually searched for THIS list, which is
     *                                 not always `$query->term` — a relaxed read searches words the
     *                                 query does not carry
     */
    public static function of(array $cards, ProductQuery $query, ?string $term, TraceRecorder $trace): self
    {
        // Ordered first so both removals see the same list, and because the ordering is what the
        // shopper asked for while the removals only take away.
        $ordered = self::wordMatched(OrderedByPrice::apply($cards, $query), $term, $trace);

        // The query's stated price range, enforced on the price the shopper will actually be shown.
        // See StatedBudget for why the database filter cannot be trusted for this.
        $kept = StatedBudget::keep($ordered, $query);
        $narrowed = \count($kept) !== \count($ordered);

        // Recorded only when something was dropped. Unconditionally would put a no-op line in the
        // trace of every search in a shop with no price rules, which is nearly all of them; never at
        // all would let cards vanish between two events that both look complete.
        if ($narrowed) {
            $trace->record('price.enforced', [
                'droppedCount' => \count($ordered) - \count($kept),
                'droppedIds' => self::missingFrom($ordered, $kept),
                // Named so a trace reader is not left guessing why a card the database returned is
                // not in the reply.
                'reason' => 'price outside the stated range once the shopper\'s own price was known',
            ]);
        }

        return new self($kept, $narrowed);
    }

    /**
     * Hits the shop's own search box would not have produced, removed before anything downstream can
     * present one. See {@see WordBoundaryMatch} for the reported failure and the measurement.
     *
     * @param list<ProductCard> $cards
     *
     * @return list<ProductCard>
     */
    private static function wordMatched(array $cards, ?string $term, TraceRecorder $trace): array
    {
        $kept = WordBoundaryMatch::keep($cards, $term);

        if (\count($kept) === \count($cards)) {
            return $kept;
        }

        $trace->record('retrieve.fragment_matches', [
            'term' => $term,
            'droppedIds' => self::missingFrom($cards, $kept),
            'reason' => 'the term appeared only inside a longer word, at no word edge',
        ]);

        return $kept;
    }

    /**
     * @param list<ProductCard> $cards
     * @param list<ProductCard> $kept
     *
     * @return list<string>
     */
    private static function missingFrom(array $cards, array $kept): array
    {
        $keptIds = array_map(static fn(ProductCard $card): string => $card->id, $kept);

        return array_values(array_diff(array_map(static fn(ProductCard $card): string => $card->id, $cards), $keptIds));
    }
}
