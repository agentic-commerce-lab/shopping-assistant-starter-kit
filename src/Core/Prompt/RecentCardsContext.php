<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Tool\ToolProductSummary;

/**
 * Names the cards the shopper was shown in the previous turn, so "that one" resolves to the product
 * they actually saw.
 *
 * ## The defect this exists for
 *
 * Measured on the staging shop, 2026-09-02. Turn 1 showed **Club Jersey (Blue, M)** `90d9b582…`.
 * Turn 2 — *"add that to my cart"* — added **Club Jersey (Red, XL)** `11c2bd10…`, a variant the
 * shopper had never seen, and announced it as though it were the one on screen. Same parent, 15
 * variants in the family, and nothing in the trace marked red: `outcome: cart_added`.
 *
 * Only prose crosses a turn boundary — see {@see \Swag\AssistantStarterKit\Core\Agent\ShopwareChatTurnRunner}'s
 * `bag()`, which stores `cardIds` and deliberately does not replay them. So the model reads
 * *"Club Jersey"* as a **name**, has no id to act on, and searches again; a search keyed on a
 * different term ranks that family's variants differently, and a different one comes back as its
 * representative. The shopper is then told about a product they were never shown, with that
 * product's stock and delivery time.
 *
 * ## Why the system prompt, and not the replayed conversation
 *
 * `bag()`'s objection is right and is not being overruled: re-injecting ids into the replayed
 * conversation would put machine tokens into shopper-facing context. The system prompt is not
 * shopper-facing, and {@see ViewingContext} already carries an id there for the product on screen.
 * This is that same mechanism, pointed one turn back.
 *
 * ## The wording, which is the whole risk
 *
 * `ViewingContext` records a live measurement in which one wrong clause — *"use your tools"* — sent
 * the model back for a lookup it did not need and took a turn from 3.7 s to **13.8 s**. This line
 * carries the mirrored risk too: too insistent, and the model keeps answering out of the previous
 * turn's shortlist after the shopper has moved on.
 *
 * So it says both halves, and both are held by tests: **use these ids rather than searching for
 * them**, and **search as usual for anything else**. Do not drop either clause.
 *
 * Turn 2 was measured at 19–40 s on staging with `openai/gpt-5-mini`, of which one model call plus
 * one `search_products` is a re-lookup of something the shop already had. Removing that is the
 * point: this fix should make the turn shorter, and it must not make it longer.
 */
final class RecentCardsContext
{
    /**
     * Enough to carry "the first one" or "the blue one", bounded so a long shortlist cannot grow the
     * prompt without limit. `SearchProductsTool` returns at most 8, so this never truncates in
     * practice — it is a ceiling against a future caller, not a working limit.
     */
    private const MAX_CARDS = 8;

    private function __construct() {}

    /**
     * @param list<ProductCard> $cards the cards rendered in the previous assistant turn
     */
    public static function line(array $cards): string
    {
        // Most turns are the first of their conversation. An empty clause on every one of them is
        // prompt tokens spent to say nothing.
        if ($cards === []) {
            return '';
        }

        // Through ToolProductSummary, exactly as ViewingContext does, and for the same reason: it is
        // an allowlist of id, name and option values. Reading the card directly here would put a
        // price and a stock level one property access away from the system prompt — and these
        // figures are a turn old, so quoting them would be worse than merely unearned.
        $described = [];

        foreach (ToolProductSummary::of(\array_slice($cards, offset: 0, length: self::MAX_CARDS)) as $summary) {
            $options = [];

            foreach ($summary['options'] as $group => $value) {
                $options[] = $group . ': ' . $value;
            }

            $described[] = \sprintf(
                '%s%s [id %s]',
                $summary['name'],
                $options === [] ? '' : ' (' . implode(', ', $options) . ')',
                $summary['id'],
            );
        }

        return \sprintf('These are the products you showed the shopper in your previous reply, and their cards '
        . 'are still on their screen: %s. When they say "that", "it", "the first one" or name '
        . 'one of these, they mean the exact product listed here — use its id and do not search '
        . 'for it again, or you will land on a different size or colour of the same product and '
        . 'tell them about one they never saw. If they ask something ABOUT one of these — why it '
        . 'suits them, how it compares, whether it is the right choice — it stays the subject: '
        . 'answer about that product and show it. You may search for context, but do not let the '
        . 'products that search returns quietly become the answer. For anything else — a different '
        . 'product, a different variant, a new question — search as usual. Never state a figure '
        . 'from this list: it is a turn old, and the cards carry the current ones.', implode('; ', $described));
    }
}
