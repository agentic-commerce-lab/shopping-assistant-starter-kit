<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * Optional gateway capability: the signed-in shopper's own order history.
 *
 * The same shape as {@see CategoryTreeReader}, and for the same reason —
 * {@see CommerceGatewayInterface} is untouched, and a gateway that does not implement this never
 * gets the tool, because {@see \Swag\AssistantStarterKit\Core\Tool\Factory\ListOrdersToolFactory}
 * checks `instanceof` before constructing one. Capability control is toolbox construction, never a
 * prompt instruction (D6).
 *
 * ## There is no identity parameter, and that absence is the design
 *
 * No customer id, no employee id, no `ShoppingContext`. An implementation resolves the shopper from
 * the request context it already holds, exactly as the DAL gateway already resolves customer-group
 * pricing. **A caller cannot name a subject, so no caller can name the wrong one** — not the model,
 * not a prompt injection, not a contributed tool. That is what replaces the sentence
 * `ARCHITECTURE.md` used to carry about `read_customer_pii` having no code path.
 *
 * ## An implementation MUST read through `AbstractOrderRoute`
 *
 * Under Shopware Commercial's B2B Components an employee is not a customer: the company is the
 * customer and employees are logins hanging off it, so every employee of one company presents the
 * **same** customer id. What separates them is Commercial's `DecoratedOrderRoute`, which adds a
 * filter on the employee's own orders unless their role carries the permission `order.read.all`,
 * before the query runs — and which is fail-closed, because an employee with no role at all still
 * gets the filter.
 *
 * That is a decoration of a **service**. Querying `order.repository` instead returns every order of
 * the business partner, silently, with no error to notice and nothing in a trace to show for it —
 * and so does injecting `DecoratedOrderRoute.inner`, which is the undecorated original. The service
 * id to wire is the concrete `Shopware\Core\Checkout\Order\SalesChannel\OrderRoute`: Symfony's
 * decoration makes that id resolve to the decorator. `DalOrderHistoryTest` asserts both the type and
 * the wiring, so this stays true after this file stops being read.
 *
 * @api Public extension point. Only DTOs from Dto\ may cross this boundary —
 *      never a Shopware entity, never SalesChannelContext.
 */
interface OrderHistoryReader
{
    /**
     * The signed-in shopper's most recent orders, newest first.
     *
     * @param int $limit at most this many; the caller has already bounded it
     *
     * @return list<OrderSummary>
     */
    public function orders(int $limit): array;
}
