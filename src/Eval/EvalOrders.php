<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
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
                orderedAt: new \DateTimeImmutable('2026-09-12'),
                stateLabel: 'Shipped',
                total: 118.44,
                currency: 'EUR',
                itemCount: 3,
                documents: [new OrderDocumentRef('Invoice', '/account/order/document/inv-10023/deep', 'pdf')],
            ),
            new OrderSummary(
                orderNumber: '10019',
                orderedAt: new \DateTimeImmutable('2026-08-28'),
                stateLabel: 'Open',
                total: 73.08,
                currency: 'EUR',
                itemCount: 1,
                documents: [],
            ),
        ];
    }
}
