<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderQuery;
use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The signed-in shopper's own recent orders.
 *
 * ## It returns order numbers, and that is the whole of its discipline
 *
 * A total, a date and a state label are figures, and D3 is that the model never supplies one. The
 * card beside the reply carries them, rendered by the server from what {@see OrderRenderer} holds —
 * the same boundary that makes the assistant structurally incapable of inventing a price, drawn
 * around a second kind of record.
 *
 * **Numbers rather than ids**, unlike the product tools. A shopper says *"10023"*, so the model has
 * to be able to match what they said to what it fetched; an opaque id would make that a second
 * lookup, which is the arithmetic that broke `search_products` before `ToolProductSummary` existed.
 * A number is safe to expose for the reason an id is not interesting: it identifies, it does not
 * assert. A number the tool never returned fails
 * {@see \Swag\AssistantStarterKit\Eval\Assertion\NoForeignOrderInProse}.
 *
 * ## What it cannot be asked
 *
 * Whose orders. {@see OrderHistoryReader::orders()} takes no subject, so there is no argument
 * through which a prompt injection could request somebody else's — see that interface for why the
 * implementation must read through `AbstractOrderRoute` for this to hold under B2B Components.
 */
#[AsTool(
    name: 'list_orders',
    description: 'The signed-in shopper\'s own recent orders, newest first. Returns order numbers '
    . 'only — the shop renders the dates, totals, states and any invoices as cards beside your '
    . 'reply. Say that you found their orders and let the cards carry the detail: never state a '
    . 'total, a date or a delivery status yourself, and never mention an order number this tool '
    . 'did not return. If it returns none, say you could not find any orders on their account.',
)]
final readonly class ListOrdersTool
{
    /**
     * What a call returns when the model does not say.
     *
     * Five, below the ceiling rather than on it: a default that sits on the maximum makes the
     * maximum the normal case, and the normal case should be a list somebody can read at a glance.
     */
    private const DEFAULT_LIMIT = 5;

    /**
     * The ceiling.
     *
     * Ten rather than the catalogue tools' eight, because an order list is one row per order with no
     * family to collapse — and lower than a page of account history, because a shopper who wants
     * their whole history wants the account page, not a chat bubble.
     */
    private const MAX_LIMIT = 10;

    public function __construct(
        private OrderHistoryReader $orders,
        private OrderRenderer $renderer,
        private TraceRecorder $trace,
    ) {}

    /**
     * @param int|null    $limit      How many orders to return, newest first. Between 1 and 10, default 5.
     * @param int|null    $withinDays Only orders from the last this many days. Omit for no limit.
     * @param string|null $state      Only orders in this state: "open", "in_progress", "completed" or
     *                                "cancelled". Omit for any state.
     *
     * @return array{orderNumbers: list<string>, total: int, filtered: bool}
     */
    public function __invoke(?int $limit = null, ?int $withinDays = null, ?string $state = null): array
    {
        // Bounds and vocabulary live in `OrderQuery`, which is the only way to build one: `#[AsTool]`
        // derives the JSON Schema from this signature by reflection and can express neither a range
        // nor an enum. It clamps the numbers and DROPS an unknown state rather than refusing — see
        // that class for why answering the unfiltered question beats failing over a word the shopper
        // never said.
        $query = OrderQuery::of($limit ?? self::DEFAULT_LIMIT, $withinDays, $state);

        $orders = $this->orders->orders($query);
        $this->renderer->registerRetrieved($orders);

        $numbers = array_map(static fn($order): string => $order->orderNumber, $orders);

        // Recorded because the assertion reads the trace rather than the renderer: a merchant reading
        // the trace and the eval suite then ask the same question of the same record.
        $this->trace->record('orders.listed', [
            'orderNumbers' => $numbers,
            'limit' => $query->limit,
            'withinDays' => $query->withinDays,
            'state' => $query->state,
        ]);

        // `filtered` so the model can tell "you have no orders" from "none in that window", which
        // are different sentences and the second one is the honest answer to a narrowed question.
        return [
            'orderNumbers' => $numbers,
            'total' => \count($numbers),
            'filtered' => $query->withinDays !== null || $query->state !== null,
        ];
    }
}
