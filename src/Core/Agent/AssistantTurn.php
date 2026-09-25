<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The result of one {@see AssistantRunner::run()} call: the shopper-facing prose, the cards
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} actually rendered (never anything the
 * model said, unsubstituted), the machine-readable outcome for logging/analytics, and every way the
 * prose can contradict the cards — see {@see Warnings}.
 */
// @mago-expect lint:excessive-parameter-list
// Seven facts about one finished turn, and the point of the class is that they travel together: the
// prose, the cards it is allowed to contradict, the machine-readable outcome, the ways it might
// contradict them, the two order slots and the checkout offer. Grouping any of them behind a
// sub-object would hide which of them the controller is allowed to render, which is the only
// question this class answers.
final readonly class AssistantTurn
{
    /**
     * @param list<ProductCard>  $cards
     * @param list<OrderSummary> $orders the orders `list_orders` retrieved, if the turn ran it —
     *                                   read from {@see \Swag\AssistantStarterKit\Core\Grounding\OrderRenderer},
     *                                   never from anything the model said, exactly like `$cards`
     */
    public function __construct(
        public string $prose,
        public array $cards,
        public string $outcome,
        public Warnings $warnings = new Warnings(),
        public array $orders = [],
        /**
         * The one order `get_order` fetched, if the turn ran it. Singular for
         * {@see \Swag\AssistantStarterKit\Core\Grounding\OrderRenderer}'s reason: a turn answers
         * one "what was in that order" question.
         */
        public ?OrderDetail $orderDetail = null,
        /**
         * Whether `go_to_checkout` found a filled cart this turn — {@see CheckoutOffer::isIn()}.
         *
         * Its own field because the outcome cannot carry it: "add it and take me to checkout" is
         * `cart_added`, which outranks `checkout_offered` and has to, since the widget refreshes the
         * header cart on it. While the link keyed off the outcome alone, that turn told the shopper a
         * link followed and rendered none. Which outcomes may show it is
         * {@see \Swag\AssistantStarterKit\Controller\CheckoutPayload}'s call, not this field's.
         */
        public bool $checkoutOffered = false,
    ) {}
}
