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
// Six facts about one finished turn, and the point of the class is that they travel together: the
// prose, the cards it is allowed to contradict, the machine-readable outcome, the ways it might
// contradict them, and the two order slots. Grouping any of them behind a sub-object would hide
// which of them the controller is allowed to render, which is the only question this class answers.
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
    ) {}
}
