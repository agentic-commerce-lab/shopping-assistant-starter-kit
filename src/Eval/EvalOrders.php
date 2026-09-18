<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * The order history a journey runs against, when it switches `enableOrderHistory` on.
 *
 * Seeded rather than derived from the catalogue fixture: an order belongs to a shopper, and that
 * fixture has none. Two of them, deliberately different from each other —
 *
 * - one **with** an invoice and one **without**, so a journey can tell "this order has no document"
 *   from "the document was not rendered", which are the same empty space on screen and not the same
 *   defect;
 * - two **different states**, because a reply that says "shipped" about both is a reply reading the
 *   first row and generalising.
 *
 * The numbers are real five-digit order numbers rather than `1` and `2`, because
 * {@see Assertion\NoForeignOrderInProse} deliberately ignores anything shorter than four digits — a
 * fixture below that bound would make the assertion pass vacuously and prove nothing.
 *
 * **The dates are relative, and that is not cosmetic.** They were fixed calendar dates until
 * `order_history_narrowed` failed 0/3 on its first run: the older order had aged to 21 days, so a
 * model that narrowed by state AND added any sensible recency window excluded it and the journey
 * fetched nothing. A fixture whose result depends on the wall clock drifts — it would have failed
 * eventually whatever the code did, and for a reason nobody could have read off the failure. Two and
 * five days old sits inside any window a model would pick, so the STATE is what separates them,
 * which is what the journey is actually measuring.
 */
final class EvalOrders
{
    private function __construct() {}

    /** @return list<OrderSummary> newest first, as the gateway returns them */
    public static function two(): array
    {
        return [
            new OrderSummary(
                orderNumber: '10023',
                orderedAt: new \DateTimeImmutable('-2 days'),
                stateLabel: 'Shipped',
                total: 118.44,
                currency: 'EUR',
                itemCount: 3,
                documents: [new OrderDocumentRef('Invoice', '/account/order/document/inv-10023/deep', 'pdf')],
            ),
            new OrderSummary(
                orderNumber: '10019',
                orderedAt: new \DateTimeImmutable('-5 days'),
                stateLabel: 'Open',
                total: 73.08,
                currency: 'EUR',
                itemCount: 1,
                documents: [],
            ),
        ];
    }

    /**
     * The same two orders, with their lines — what `get_order` answers.
     *
     * The line labels are deliberately NOT catalogue product names: an order stores the label as it
     * was, and a journey whose order lines matched the fixture catalogue could not tell "named the
     * order's items" from "named a product it happened to find".
     *
     * @return list<OrderDetail>
     */
    public static function detailsOfTwo(): array
    {
        return [
            new OrderDetail(
                orderNumber: '10023',
                orderedAt: new \DateTimeImmutable('-2 days'),
                stateLabel: 'Shipped',
                total: 118.44,
                currency: 'EUR',
                lines: [
                    new OrderLine('Chain Oil 100ml', 3, 12.90, 38.70),
                    new OrderLine('Brake Pad Set Rear', 1, 79.74, 79.74),
                ],
                documents: [new OrderDocumentRef('Invoice', '/account/order/document/inv-10023/deep', 'pdf')],
            ),
            new OrderDetail(
                orderNumber: '10019',
                orderedAt: new \DateTimeImmutable('-5 days'),
                stateLabel: 'Open',
                total: 73.08,
                currency: 'EUR',
                lines: [new OrderLine('Roadside Repair Kit', 1, 73.08, 73.08)],
                documents: [],
            ),
        ];
    }
}
