<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;

/**
 * @internal an order reader that records the bound it was asked for
 *
 * Named rather than anonymous so `$askedFor` is reachable through a declared type: the bound the
 * tool computes is the thing under test in `ListOrdersToolTest`, and an anonymous class hides it
 * behind the interface it implements.
 */
final class RecordingOrderHistory implements OrderHistoryReader
{
    public int $askedFor = 0;

    public function __construct(
        private readonly int $count = 0,
    ) {}

    /** @return list<OrderSummary> */
    public function orders(int $limit): array
    {
        $this->askedFor = $limit;
        $orders = [];

        for ($index = 0; $index < min($this->count, $limit); ++$index) {
            $orders[] = new OrderSummary(
                orderNumber: (string) (10000 + $index),
                orderedAt: new \DateTimeImmutable('2026-09-12'),
                stateLabel: 'Shipped',
                total: 10.0,
                currency: 'EUR',
                itemCount: 1,
                documents: [],
            );
        }

        return $orders;
    }
}
