<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The result of one {@see AssistantRunner::run()} call: the shopper-facing prose, the cards
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} actually rendered (never anything the
 * model said, unsubstituted), the machine-readable outcome for logging/analytics, and every way the
 * prose can contradict the cards — see {@see Warnings}.
 */
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
    ) {}
}
