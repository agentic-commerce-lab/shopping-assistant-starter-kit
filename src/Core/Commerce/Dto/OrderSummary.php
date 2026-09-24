<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One of the shopper's own orders, as the card shows it.
 *
 * **What is deliberately absent:** customer name, address, email, employee id, payment detail,
 * billing address. The assistant is talking to the shopper — it does not need to be told who they
 * are, and everything on this class reaches the wire through
 * {@see \Swag\AssistantStarterKit\Controller\OrderPayload}. `OrderSummaryTest` asserts the property
 * list rather than trusting it, because a field about a *person* added here in a hurry would ship
 * without anyone deciding to ship it.
 *
 * `$stateLabel` is Shopware's own translated label, carried rather than derived. Mapping the state
 * machine ourselves would give the card a vocabulary the shopper's account page does not use, and
 * two words for one state is worse than either word.
 *
 * `$total` was the figure the model must never state (D3) until 2026-09-24, when D3 was relaxed for
 * the shopper's own orders: {@see \Swag\AssistantStarterKit\Core\Tool\ListOrdersTool} now hands it
 * over through {@see \Swag\AssistantStarterKit\Core\Tool\ToolOrderFacts}, which picks the fields the
 * model may see — never `$documents`. The card beside the reply is still rendered from this object.
 */
// @mago-expect lint:excessive-parameter-list
// Seven fields, and every one of them is a field the card shows — the class is the card's contract,
// so the list shrinks only by removing something from the screen. Grouping them behind a shape would
// also defeat `OrderSummaryTest`, which asserts this exact property list precisely because this is
// where a field about a *person* would enter the pipeline.
final readonly class OrderSummary
{
    /** @param list<OrderDocumentRef> $documents */
    public function __construct(
        public string $orderNumber,
        public \DateTimeImmutable $orderedAt,
        public string $stateLabel,
        public float $total,
        public string $currency,
        public int $itemCount,
        public array $documents,
    ) {}
}
