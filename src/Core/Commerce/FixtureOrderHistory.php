<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderQuery;
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

    /** @var array<string, OrderDetail> keyed by order number */
    private array $details = [];

    /** @param list<OrderSummary> $orders */
    public function seed(array $orders): void
    {
        $this->orders = array_values($orders);
    }

    /** @param list<OrderDetail> $details */
    public function seedDetails(array $details): void
    {
        $this->details = [];

        foreach ($details as $detail) {
            $this->details[$detail->orderNumber] = $detail;
        }
    }

    /**
     * Null for anything not seeded — which stands in for both "no such order" and "not yours", the
     * same single answer the real implementation gives and for the same reason.
     */
    public function order(string $orderNumber): ?OrderDetail
    {
        return $this->details[$orderNumber] ?? null;
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
    public function orders(OrderQuery $query): array
    {
        $matching = array_values(array_filter($this->orders, static function (OrderSummary $order) use ($query): bool {
            // The state fixture carries Shopware's label, not its technical name — so the match
            // is case-insensitive on a normalised label. Production filters on
            // `stateMachineState.technicalName`; this is the closest a fixture with no state
            // machine can get, and the difference is recorded rather than hidden.
            if ($query->state !== null && strtolower(str_replace(' ', '_', $order->stateLabel)) !== $query->state) {
                return false;
            }

            if ($query->withinDays === null) {
                return true;
            }

            $cutoff = (new \DateTimeImmutable('now'))->modify(\sprintf('-%d days', $query->withinDays));

            return $order->orderedAt >= $cutoff;
        }));

        usort($matching, static fn(OrderSummary $a, OrderSummary $b): int => $b->orderedAt <=> $a->orderedAt);

        return \array_slice($matching, offset: 0, length: $query->limit);
    }
}
