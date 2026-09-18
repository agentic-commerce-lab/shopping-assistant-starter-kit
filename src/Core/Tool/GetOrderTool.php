<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What was in one of the shopper's own orders.
 *
 * ## It names the items and counts nothing
 *
 * D3 applied where it is easiest to forget. A quantity is a figure — "you ordered three of them" is
 * exactly the kind of sentence a model produces confidently and wrongly — so the return shape
 * carries line NAMES and nothing else, and the card beside the reply carries the quantity, the unit
 * price and the line total. This follows {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary},
 * which returns `{id, name, options, properties}` and deliberately no figure either.
 *
 * ## An order it cannot see and an order that never existed are the same answer
 *
 * The gateway returns null for both, and this returns `found: false` for both — see
 * {@see OrderHistoryReader::order()} on why telling them apart would tell a shopper that somebody
 * else's order exists. The prose the model is asked for is the same sentence in both cases, which is
 * the point.
 */
#[AsTool(
    name: 'get_order',
    description: 'What was in one of the signed-in shopper\'s own orders, by order number. Returns '
    . 'the item names only — the shop renders the quantities, prices and any invoice as a card '
    . 'beside your reply. Name the items and let the card carry the numbers: never state a '
    . 'quantity, a price or a total yourself. If "found" is false, say you could not find that '
    . 'order on their account and offer to list their recent orders instead — do not speculate '
    . 'about why, and never suggest it belongs to somebody else.',
)]
final readonly class GetOrderTool
{
    /**
     * The longest argument worth asking the gateway about.
     *
     * Shopware's order numbers are short; anything approaching this is a model that has pasted
     * something else into the field. Refused here rather than in the schema, because `#[AsTool]`
     * derives that by reflection and cannot express `maxLength` — the same regression every other
     * tool in this namespace closes with a guard clause.
     */
    private const MAX_LENGTH = 64;

    public function __construct(
        private OrderHistoryReader $orders,
        private OrderRenderer $renderer,
        private TraceRecorder $trace,
    ) {}

    /**
     * @param string $orderNumber The order number, as the shopper said it — e.g. "10023".
     *
     * @return array{orderNumber: string, items: list<string>, found: bool}
     */
    public function __invoke(string $orderNumber): array
    {
        $number = trim($orderNumber);

        $detail = $number === '' || \strlen($number) > self::MAX_LENGTH ? null : $this->orders->order($number);

        if ($detail === null) {
            $this->trace->record('orders.detail', ['orderNumber' => $number, 'found' => false]);

            return ['orderNumber' => $number, 'items' => [], 'found' => false];
        }

        $this->renderer->registerDetail($detail);

        $items = array_map(static fn($line): string => $line->name, $detail->lines);

        $this->trace->record('orders.detail', [
            'orderNumber' => $detail->orderNumber,
            'found' => true,
            'lineCount' => \count($items),
        ]);

        return ['orderNumber' => $detail->orderNumber, 'items' => $items, 'found' => true];
    }
}
