<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderQuery;
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

    public ?OrderQuery $lastQuery = null;

    public function __construct(
        private readonly int $count = 0,
    ) {}

    /** @return list<OrderSummary> */
    public function orders(OrderQuery $query): array
    {
        $this->askedFor = $query->limit;
        $this->lastQuery = $query;
        $orders = [];

        for ($index = 0; $index < min($this->count, $query->limit); ++$index) {
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

    public function order(string $orderNumber): ?OrderDetail
    {
        return null;
    }
}
