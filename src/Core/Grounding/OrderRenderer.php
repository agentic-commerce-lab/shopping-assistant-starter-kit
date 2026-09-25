<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * What {@see FactRenderer} is for products, this is for orders: the request-scoped authority on
 * which orders this turn actually retrieved.
 *
 * {@see \Swag\AssistantStarterKit\Core\Tool\ListOrdersTool} registers what it fetched and hands the
 * model the same orders; the controller reads this afterwards and builds the payload. **The model's
 * return value never becomes a card** — the same guarantee, drawn around the same boundary, as the
 * one that makes the assistant structurally incapable of inventing a price. Since 2026-09-24 the
 * model may state these figures too (D3 relaxed for the shopper's own orders), but what the card
 * shows still comes from here and never from the sentence beside it.
 *
 * ## Separate from FactRenderer rather than folded into it
 *
 * That class renders `ProductCard`s and backs the price, availability and property audits, all three
 * of which answer "did the model state a figure nothing gave it". An order is not a product.
 * `FactRenderer`'s own docblock already records that it is over its method budget and that the split
 * worth doing is "per-turn state" against "audit entry points"; adding an unrelated entity to it
 * would be the opposite of that split. The price audit does now accept this class's figures as
 * backing, but as an argument ({@see OrderFigures}), the way it accepts shop-information passages —
 * so `unbackedPrices()` still answers one question and this class stays out of `FactRenderer`.
 *
 * ## Registration replaces, it never merges
 *
 * Including with an empty array, which resets it — the same rule and the same reason as
 * `FactRenderer::registerRetrieved()`. If the last call returned nothing, the honest card set is
 * nothing. Merging would leave the previous turn's orders on screen beside a reply that found none,
 * which is the one thing a shopper reading their own order history must never see.
 */
final class OrderRenderer
{
    /** @var list<OrderSummary> */
    private array $orders = [];

    private ?OrderDetail $detail = null;

    /** @param list<OrderSummary> $orders */
    public function registerRetrieved(array $orders): void
    {
        $this->orders = array_values($orders);
    }

    /** @return list<OrderSummary> every order this turn retrieved, in the order it retrieved them */
    public function retrievedOrders(): array
    {
        return $this->orders;
    }

    /**
     * The one order {@see \Swag\AssistantStarterKit\Core\Tool\GetOrderTool} fetched this turn.
     *
     * Singular, not a list: a turn answers one "what was in that order" question, and holding a
     * collection would invite a card layer to render several and a shopper to read a set that no
     * single question asked for. Replaced on every call, like the summaries above.
     */
    public function registerDetail(OrderDetail $detail): void
    {
        $this->detail = $detail;
    }

    public function retrievedDetail(): ?OrderDetail
    {
        return $this->detail;
    }
}
