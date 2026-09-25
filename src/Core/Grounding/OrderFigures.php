<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;

/**
 * Every figure an order tool handed the model this turn, as the price audit reads figures.
 *
 * The fourth source {@see BackedFigures} accepts, added when D3 was relaxed for the shopper's own
 * orders on 2026-09-24 and the model started saying "your order came to €118.44". The price audit
 * measured that sentence against the rendered product cards — an order turn has none — so every
 * correct order figure was recorded in `claims.audit` as `modelClaimsDiscarded`, beside the invented
 * catalogue prices that stage exists to surface.
 *
 * Read from {@see OrderRenderer}, not the trace: it holds exactly what `list_orders` and `get_order`
 * fetched and returned this turn, and keeps order totals out of an event table a merchant exports.
 * Empty on every turn that fetched no order, so nothing here can back a catalogue price.
 *
 * **Amounts exactly, and one date shape.** Order totals, line unit prices and line totals, as the
 * numbers they are — a figure the model rounded, converted or added up matches none of them and is
 * still reported. The date is here for a narrower reason: {@see CurrencyFigureExtractor} accepts any
 * two-decimal number as a possible price, so a German reply's "vom 12.09.2026" reads as the price
 * `12.09`. That is the order date the tool returned, so its day-and-month reading is backed too.
 */
final class OrderFigures
{
    private function __construct() {}

    /** @return list<float> */
    public static function of(OrderRenderer $orders): array
    {
        $figures = [];

        foreach ($orders->retrievedOrders() as $order) {
            $figures[] = $order->total;
            $figures[] = self::asDayAndMonth($order->orderedAt);
        }

        $detail = $orders->retrievedDetail();

        return $detail === null ? $figures : [...$figures, ...self::ofDetail($detail)];
    }

    /** @return list<float> */
    private static function ofDetail(OrderDetail $detail): array
    {
        $figures = [$detail->total, self::asDayAndMonth($detail->orderedAt)];

        foreach ($detail->lines as $line) {
            $figures[] = $line->unitPrice;
            $figures[] = $line->lineTotal;
        }

        return $figures;
    }

    /**
     * 12 September as `12.09`, which is how "12.09.2026" leaves the price extractor — and read the way
     * {@see ProseAudit::unbackedPrices()} reads that figure, from the same string shape.
     */
    private static function asDayAndMonth(\DateTimeImmutable $date): float
    {
        $dayAndMonth = $date->format('j.m');

        // Always numeric ("12.09"); the check is the narrowing the analyzer needs before the cast.
        return \is_numeric($dayAndMonth) ? (float) $dayAndMonth : 0.0;
    }
}
