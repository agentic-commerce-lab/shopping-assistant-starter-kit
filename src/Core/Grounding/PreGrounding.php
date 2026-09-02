<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * What a turn already knows about before any tool runs, registered with {@see FactRenderer}.
 *
 * Two seeds reach a turn from the request itself: the product the shopper has **open**
 * ({@see \Swag\AssistantStarterKit\Core\Prompt\ViewingContext}) and the cards the **previous reply**
 * rendered ({@see \Swag\AssistantStarterKit\Core\Prompt\RecentCardsContext}). Both have to be
 * *nameable*: their ids reach the prompt, and {@see FactRenderer::validate()} must not count one as an
 * invention when the reply names it. That is what makes *"add that one"* resolve to the variant the
 * shopper actually saw instead of a sibling found by searching the name again.
 *
 * **Only one of them may be the turn's default rendered card set**, and it is the product on screen.
 * That product is in front of the shopper while they ask, so rendering its card beside an answer that
 * names nothing is coherent — and it is what lets a turn calling no tool answer about it with a real
 * price and stock, without a round trip to fetch what the server already had.
 *
 * A shortlist from the *previous question* is not that. Measured on the staging shop, 2026-09-02:
 * turn one asked *"Do you sell bikes?"*, correctly answered no, mentioned Bike Wash 1L and rendered
 * its card. Turn two asked *"what is the return policy"* and got a correct 30-day answer out of the
 * shop's own documents — with the Bike Wash card rendered underneath it:
 *
 * ```
 * 45 tool.call         {"name":"search_shop_info","stage":"dispatch"}
 * 48 grounding.select  {"source":"last_tool_batch","selectedIds":["c65a030e…"]}
 * 51 turn.end          {"cards":["c65a030e…"],"outcome":"product_shown"}
 * ```
 *
 * `search_shop_info` registers no product batch, so the seed was still standing in as the default and
 * a policy answer was filed as `product_shown`.
 *
 * ## Why the reset is an empty registration
 *
 * {@see FactRenderer::registerRetrieved()} accumulates the authoritative index and *replaces* the
 * last batch — including with an empty array, which its own contract documents as the honest default
 * for "the last call returned nothing". That is exactly the state wanted here: recalled cards stay
 * nameable, and nothing stands in as the default. It is done in this class rather than as another
 * method on `FactRenderer` because that class is at this project's per-file line ceiling and already
 * carries a `too-many-methods` exemption — the same reason {@see RetrievedProductIndex} and
 * {@see ProductNameIndex} live beside it rather than inside it.
 *
 * A follow-up that really is about those products still renders them: the reply names one, prose
 * narrowing accepts it out of the authoritative set, and it renders. What is given up is only the
 * card beside a reply that mentions no product at all — which is the defect.
 */
final class PreGrounding
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $recentCards the cards the previous assistant reply rendered
     * @param ?ProductCard      $viewing     the product the shopper has open, if any
     */
    public static function seed(FactRenderer $renderer, array $recentCards, ?ProductCard $viewing): void
    {
        if ($recentCards !== []) {
            $renderer->registerRetrieved($recentCards);
        }

        // Registered last, so it is the batch left standing.
        if ($viewing !== null) {
            $renderer->registerRetrieved([$viewing]);

            return;
        }

        // Nothing is on screen, so nothing may stand in as the default — see the class docblock.
        if ($recentCards !== []) {
            $renderer->registerRetrieved([]);
        }
    }
}
