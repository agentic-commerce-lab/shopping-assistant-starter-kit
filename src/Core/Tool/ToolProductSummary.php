<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The shape a tool returns for a retrieved product: **id, name and option values — and nothing
 * else.**
 *
 * ## Why this exists at all
 *
 * Tools used to return bare ids, which is the strictest possible reading of D3 ("the model emits
 * ids, the server renders facts"). The first trace ever read end to end on this branch showed what
 * that costs. Asked *"do you have the trail jersey in blue, size M?"* against the real catalogue,
 * the model searched by term, received **seven opaque ids**, and then called `get_product` once per
 * id purely to find out which was which. It exhausted `maxToolCallsPerTurn` on the fifth call and
 * the turn ended in `tool_limit_exceeded`.
 *
 * That is not a model weakness, it is arithmetic: **with opaque ids, identifying one of N candidates
 * costs N tool calls**, so any family larger than the call budget is unanswerable. Against the
 * fixture catalogue no family exceeds four variants, which is why it looked like run-to-run variance
 * instead of a structural bound — and it is the most likely real cause of the handoff's known-issue 4
 * (`cart_add` 0/3, "2 of 6 runs exhausted the tool-call budget"): the budget is spent on identifying
 * products before `add_to_cart` is ever reachable.
 *
 * ## Why this does not weaken D3
 *
 * D3's substance is that **the model never supplies a figure**. Nothing here is a figure: no price,
 * no stock, no delivery time, no availability. Those are still rendered exclusively by
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} from the card the server holds.
 *
 * Option values are not new information to the model either — since ruling R54 the system prompt
 * already carries the catalogue's own vocabulary, including every option value. So this opens no
 * fabrication surface that was not already open; it only lets the model tell two retrieved products
 * apart without paying a round trip for each.
 *
 * **Never widen this.** A price or stock number here would let the model quote a figure it did not
 * have to earn, which is the one thing this whole pipeline exists to prevent.
 */
final class ToolProductSummary
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards
     *
     * @return list<array{id: string, name: string, options: array<string, string>}>
     */
    public static function of(array $cards): array
    {
        return array_map(static fn(ProductCard $card): array => [
            'id' => $card->id,
            'name' => $card->name,
            'options' => $card->options,
        ], $cards);
    }
}
