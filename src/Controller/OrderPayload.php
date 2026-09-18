<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * Serialises the orders a turn retrieved for the wire.
 *
 * The same shape and the same discipline as {@see CardPayload}: **every figure here comes from an
 * {@see OrderSummary} the renderer holds**, and nothing is parsed out of the model's prose. A field
 * added that reads anything else reopens the gap ruling R47 closed, in a place where the figure is
 * the shopper's own money.
 *
 * `orderedAt` is serialised as a plain `Y-m-d` date rather than an ISO timestamp: the card shows a
 * day, the shop's own account page shows a day, and handing the client a time it will only throw
 * away invites two surfaces to disagree about time zones for no benefit.
 *
 * Nothing here identifies the shopper — see {@see OrderSummary} for what it deliberately does not
 * carry, and the test that keeps it that way.
 */
final readonly class OrderPayload
{
    /**
     * @param list<OrderSummary> $orders
     *
     * @return list<array<string, mixed>>
     */
    public function of(array $orders): array
    {
        return array_map(static fn(OrderSummary $order): array => [
            'orderNumber' => $order->orderNumber,
            'orderedAt' => $order->orderedAt->format('Y-m-d'),
            'state' => $order->stateLabel,
            'total' => $order->total,
            'currency' => $order->currency,
            'itemCount' => $order->itemCount,
            'documents' => array_map(static fn($document): array => [
                'title' => $document->title,
                'url' => $document->url,
                'extension' => $document->extension,
            ], $order->documents),
        ], $orders);
    }

    /**
     * The one order `get_order` fetched, or null.
     *
     * Same discipline as {@see self::of()}: every value comes from the {@see OrderDetail} the
     * renderer holds. The line figures are here precisely because the model never saw them — it was
     * given names, and this is where the numbers rejoin them.
     *
     * @return array<string, mixed>|null
     */
    public function detail(?OrderDetail $detail): ?array
    {
        if ($detail === null) {
            return null;
        }

        return [
            'orderNumber' => $detail->orderNumber,
            'orderedAt' => $detail->orderedAt->format('Y-m-d'),
            'state' => $detail->stateLabel,
            'total' => $detail->total,
            'currency' => $detail->currency,
            'lines' => array_map(static fn(OrderLine $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unitPrice,
                'lineTotal' => $line->lineTotal,
            ], $detail->lines),
            'documents' => array_map(static fn($document): array => [
                'title' => $document->title,
                'url' => $document->url,
                'extension' => $document->extension,
            ], $detail->documents),
        ];
    }
}
