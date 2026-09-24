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
 * ## It returns each order's figures, and D3 yields here and nowhere else
 *
 * Until 2026-09-24 this returned order numbers only: a total, a date and a state label are figures,
 * and D3 is that the model never supplies one. That rule was relaxed for the shopper's own orders —
 * see {@see ToolOrderFacts} for why, and for exactly what crosses. The card is still rendered by the
 * server from what {@see OrderRenderer} holds, never from the reply, so a figure the model gets wrong
 * is wrong in one sentence and right on the card beside it. The catalogue keeps D3 unchanged.
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
    description: 'The signed-in shopper\'s own recent orders, newest first. Each order comes with '
    . 'its date ("orderedAt"), its state as the shop labels it, its total and currency, and how '
    . 'many items it had; "orderCount" is how many orders this call returned, not how many they '
    . 'have ever placed. Narrow with "state" (one of "open", "in_progress", "completed", '
    . '"cancelled") or "withinDays" (a number of days back) whenever the shopper asks about PART of their history '
    . 'rather than all of it — "still open", "not sorted out yet" and "outstanding" all mean state '
    . '"open"; "already delivered" or "done" mean "completed"; "recent" or a named period means '
    . 'withinDays. Listing everything when they asked for a subset makes them do the filtering they '
    . 'asked you for. If nothing matches a narrowed question, say none matched rather than that they '
    . 'have no orders. '
    . '"withDocuments" lists the orders that have an invoice or other document attached; the card '
    . 'for each of those carries it as a download. Asked about invoices or receipts, say WHICH '
    . 'orders have one and point at the download beside them — and if "withDocuments" is empty, say '
    . 'none of these orders has a document yet rather than listing the orders as if that answered '
    . 'it. Never invent a document name, a file or a link: the card carries the only one there is. '
    . 'Asked about the status, date or total of their orders, answer from these fields: you may '
    . 'state them exactly as returned, but never round, convert or add them up, never guess one '
    . 'that is missing, and never mention an order number this tool did not return. If it returns '
    . 'none, say you could not find any orders on their account.',
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
     * @return array{
     *     orders: list<array{orderNumber: string, orderedAt: string, state: string, total: float, currency: string, itemCount: int}>,
     *     withDocuments: list<string>,
     *     orderCount: int,
     *     filtered: bool,
     * }
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

        // WHICH orders carry a document, and nothing more about them. The model needs this to answer
        // "show me my invoices" truthfully — including "none of them have one", which it could not
        // say before and which is the honest answer often enough to matter.
        //
        // The title, the file type and above all the URL stay out — a document URL in the model's
        // context is precisely what the server-rendered link on the card exists to avoid, and the
        // relaxation of D3 that lets the figures below through covers figures, not links.
        $withDocuments = array_values(array_map(
            static fn($order): string => $order->orderNumber,
            array_filter($orders, static fn($order): bool => $order->documents !== []),
        ));

        // Recorded because the assertion reads the trace rather than the renderer: a merchant reading
        // the trace and the eval suite then ask the same question of the same record.
        $this->trace->record('orders.listed', [
            'orderNumbers' => $numbers,
            'limit' => $query->limit,
            'withinDays' => $query->withinDays,
            'state' => $query->state,
            'withDocuments' => $withDocuments,
        ]);

        // `filtered` so the model can tell "you have no orders" from "none in that window", which
        // are different sentences and the second one is the honest answer to a narrowed question.
        //
        // `orderCount` was `total` until each order gained a `total` of its own: beside money, a bare
        // `total` reads as money. The rename also stops `tool.result` recording it by value as
        // `total`, which the insights' search metrics read as "a product search returned N" — so a
        // turn that listed orders was being counted as a catalogue search.
        return [
            'orders' => array_map(ToolOrderFacts::summary(...), $orders),
            'withDocuments' => $withDocuments,
            'orderCount' => \count($numbers),
            'filtered' => $query->withinDays !== null || $query->state !== null,
        ];
    }
}
