<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * The order history {@see FixtureCommerceGateway} hands out, held here rather than on that class.
 *
 * Its own file for the reason the production gateway has
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderHistory}: the fixture gateway is already
 * at mago's class-complexity threshold, and this codebase splits rather than raising the bar. The
 * two gateways then have the same shape, which is worth something on its own — a reader who has seen
 * one knows where to look in the other.
 *
 * Orders are **seeded**, never derived from the catalogue fixture: an order belongs to a shopper, and
 * that fixture has no shoppers. A journey that wants order history says which orders exist.
 */
final class FixtureOrderHistory
{
    /** @var list<OrderSummary> */
    private array $orders = [];

    /** @param list<OrderSummary> $orders */
    public function seed(array $orders): void
    {
        $this->orders = array_values($orders);
    }

    /**
     * Newest first, matching {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderHistory}'s own
     * sort.
     *
     * A fixture that returned a different order than production is a fixture that hides an ordering
     * bug rather than catching one.
     *
     * @return list<OrderSummary>
     */
    public function orders(int $limit): array
    {
        $sorted = $this->orders;
        usort($sorted, static fn(OrderSummary $a, OrderSummary $b): int => $b->orderedAt <=> $a->orderedAt);

        return \array_slice($sorted, offset: 0, length: $limit);
    }
}
