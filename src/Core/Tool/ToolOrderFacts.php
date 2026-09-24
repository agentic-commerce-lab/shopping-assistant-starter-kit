<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * An order as the MODEL sees it — the counterpart of {@see ToolProductSummary}, and the line where the
 * 2026-09-24 relaxation of D3 is drawn.
 *
 * ## Why the model is handed order figures at all
 *
 * D3 says the model never states a figure; the server renders it. For the catalogue that still holds
 * without exception. For the signed-in shopper's own orders it was relaxed, because the rule had
 * stopped protecting anyone: testers asked *"what's the status of my latest orders?"* and *"total
 * spent?"* beside cards already showing both, and a model that had been handed neither could only
 * refuse or escalate. And when asked to order the same again it re-added one of each, because nobody
 * had told it a quantity — the rule designed to stop a wrong figure being said made one get acted on.
 *
 * Three things make orders a different risk from prices. The figure is historical — a total does not
 * drift the way a price or a stock level does between retrieval and reading. It is the shopper's own
 * record, not a claim the shop makes to win a sale. And the reply is still audited:
 * {@see \Swag\AssistantStarterKit\Core\Grounding\OrderFigures} makes exactly these amounts count as
 * backed this turn, so a figure the model rounds, sums or invents is still recorded as unbacked.
 *
 * ## What stays out
 *
 * Everything on the card that is not a figure about the order itself. **No document title or URL** —
 * `ListOrdersTool` says only WHICH orders carry one, and the link stays server-rendered (D3 was
 * relaxed for figures, not for URLs). Nothing about a person, because {@see OrderSummary} and
 * {@see OrderDetail} carry nothing about a person, and the key lists here are asserted by the tool
 * tests so a field cannot join without somebody deciding it should.
 */
final class ToolOrderFacts
{
    /**
     * A day, as {@see \Swag\AssistantStarterKit\Controller\OrderPayload} serialises it for the card,
     * so the reply and the card cannot disagree about which day it was.
     */
    private const DATE_FORMAT = 'Y-m-d';

    private function __construct() {}

    /**
     * @return array{orderNumber: string, orderedAt: string, state: string, total: float, currency: string, itemCount: int}
     */
    public static function summary(OrderSummary $order): array
    {
        return [
            'orderNumber' => $order->orderNumber,
            'orderedAt' => $order->orderedAt->format(self::DATE_FORMAT),
            'state' => $order->stateLabel,
            'total' => $order->total,
            'currency' => $order->currency,
            'itemCount' => $order->itemCount,
        ];
    }

    /**
     * @return array{
     *     orderNumber: string,
     *     orderedAt: string,
     *     state: string,
     *     total: float,
     *     currency: string,
     *     lines: list<array{name: string, quantity: int, unitPrice: float, lineTotal: float}>,
     * }
     */
    public static function detail(OrderDetail $detail): array
    {
        return [
            'orderNumber' => $detail->orderNumber,
            'orderedAt' => $detail->orderedAt->format(self::DATE_FORMAT),
            'state' => $detail->stateLabel,
            'total' => $detail->total,
            'currency' => $detail->currency,
            'lines' => array_map(static fn(OrderLine $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unitPrice,
                'lineTotal' => $line->lineTotal,
            ], $detail->lines),
        ];
    }
}
