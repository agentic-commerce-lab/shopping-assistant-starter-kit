<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * One of the shopper's own orders, with its lines.
 *
 * {@see OrderSummary} with `lines` instead of `itemCount` — a summary answers "which orders do I
 * have", this answers "what was in that one". They are separate classes rather than one optional
 * field because a list of ten orders carrying every line of each is a payload nobody asked for.
 *
 * **What is deliberately absent, and why it matters more here than on the summary:** no customer
 * name, no billing or shipping address, no email, no payment method, no employee id. This is the
 * richest record the feature reads, and Shopware has already loaded the address and the transaction
 * onto the entity it is mapped from — so the only thing keeping them out is this class's shape and
 * the test that asserts it.
 */
// @mago-expect lint:excessive-parameter-list
// Seven fields, and every one of them is on the card — the class is the card's contract, so the list
// shrinks only by removing something from the screen. Grouping them behind a shape would also defeat
// `OrderDetailTest`, which asserts this exact property list precisely because this is the richest
// record the feature reads and the one an address would enter through.
final readonly class OrderDetail
{
    /**
     * @param list<OrderLine>        $lines
     * @param list<OrderDocumentRef> $documents
     */
    public function __construct(
        public string $orderNumber,
        public \DateTimeImmutable $orderedAt,
        public string $stateLabel,
        public float $total,
        public string $currency,
        public array $lines,
        public array $documents,
    ) {}
}
