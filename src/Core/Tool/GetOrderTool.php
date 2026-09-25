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
 * ## It returns the lines with their figures
 *
 * Until 2026-09-24 this returned line NAMES and nothing else, on D3's reasoning that "you ordered
 * three of them" is a sentence a model produces confidently and wrongly. The cost turned out to be the
 * opposite failure: asked to order the same again, a tester's assistant re-added one of each, because
 * no quantity had ever been handed over and it had to assume one. D3 was relaxed for the shopper's own
 * orders — see {@see ToolOrderFacts} for why and for exactly what crosses — and the lines now carry
 * quantity, unit price and line total. The card is still rendered by the server from what
 * {@see OrderRenderer} holds; the catalogue tools keep D3 unchanged.
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
    . 'its date, its state as the shop labels it, its total and currency, and every line with its '
    . 'name, quantity, unit price and line total. You may state those exactly as returned, but '
    . 'never round, convert or recompute them, and never guess one that is missing. If they want '
    . 'to order the same again, these quantities are the ones to use. The shop shows any invoice '
    . 'beside your reply: never write a link or invent a document. If "found" is false, say you '
    . 'could not find that order on their account and offer to list their recent orders instead — '
    . 'do not speculate about why, and never suggest it belongs to somebody else.',
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
     * @return array{orderNumber: string, lines: array{}, found: false}|array{
     *     orderNumber: string,
     *     orderedAt: string,
     *     state: string,
     *     total: float,
     *     currency: string,
     *     lines: list<array{name: string, quantity: int, unitPrice: float, lineTotal: float}>,
     *     found: true,
     * }
     */
    public function __invoke(string $orderNumber): array
    {
        $number = trim($orderNumber);

        $detail = $number === '' || \strlen($number) > self::MAX_LENGTH ? null : $this->orders->order($number);

        if ($detail === null) {
            $this->trace->record('orders.detail', ['orderNumber' => $number, 'found' => false]);

            return ['orderNumber' => $number, 'lines' => [], 'found' => false];
        }

        $this->renderer->registerDetail($detail);

        $this->trace->record('orders.detail', [
            'orderNumber' => $detail->orderNumber,
            'found' => true,
            'lineCount' => \count($detail->lines),
        ]);

        return [...ToolOrderFacts::detail($detail), 'found' => true];
    }
}
