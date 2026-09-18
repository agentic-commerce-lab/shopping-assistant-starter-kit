<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The shopper's own orders, read through Shopware's route rather than its tables.
 *
 * ## Why the route, and never the repository
 *
 * Under Commercial's B2B Components an employee is not a customer: the company is the customer, and
 * every employee of one company presents the **same** customer id. What separates them is
 * `DecoratedOrderRoute`, which adds a filter restricting the result to the employee's own orders
 * unless their role carries `order.read.all`, and which is fail-closed — an employee with no role at
 * all still gets the filter.
 *
 * That is a decoration of a **service**. A query against `order.repository` returns every order of
 * the business partner instead: silently, with no error, and with nothing in a trace to show for it.
 * `DalOrderHistoryTest` asserts the dependency by type so this stays true after this docblock stops
 * being read.
 *
 * ## Which service id, and the mistake that is easy to make here
 *
 * The **type** is `AbstractOrderRoute`, but the **service id** wired in `services.xml` is the
 * concrete `Shopware\Core\Checkout\Order\SalesChannel\OrderRoute`. Verified on a shop running
 * Commercial 7.13.0: that id is a *public alias* for `DecoratedOrderRoute`, because Symfony's
 * decoration gives the decorator the decorated service's id.
 *
 * Two neighbouring ids are wrong, in opposite ways, and both look right:
 *
 * - `AbstractOrderRoute` is **not a registered service at all** and fails at container compile time.
 *   It was wired that way first, and no unit test could see it — the container is not built in the
 *   suite.
 * - `DecoratedOrderRoute.inner` is the **original, undecorated** route. Injecting it would compile,
 *   run, and return every order of the business partner to every employee.
 *
 * On a shop without Commercial the same id is core's own `OrderRoute`, whose customer filter is the
 * whole scope and correct there. **Neither branch is ours to implement**, which is the point.
 *
 * ## Documents
 *
 * `OrderRoute` already associates them, filtered to `config.displayInCustomerAccount = true` and
 * `sent = true`. Which invoices a customer may see is the merchant's answer, given through Shopware,
 * and we add no filter of our own — here or anywhere.
 *
 * Turning an entity into an {@see OrderSummary} lives in {@see DalOrderMapper}, beside
 * {@see DalProductCardMapper} and for the same reason: the null-handling a Shopware entity needs put
 * this class over mago's complexity threshold, and this codebase splits rather than raising the bar.
 * What stays here is the one thing worth reading in one place — which service the orders come from.
 */
final readonly class DalOrderHistory implements OrderHistoryReader
{
    public function __construct(
        private AbstractOrderRoute $orderRoute,
        private SalesChannelContextProvider $contexts,
        private RequestStack $requests,
        private DalOrderMapper $mapper,
    ) {}

    /** @return list<OrderSummary> */
    public function orders(int $limit): array
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('orderDateTime', FieldSorting::DESCENDING));
        $criteria->setLimit($limit);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('currency');
        // Without this every document is titled "Document". `OrderRoute` creates the `documents`
        // association to filter it, but adds nothing nested, and an unloaded to-one returns null —
        // so the fallback in self::documents() fired on every row and an invoice, a credit note and
        // a delivery note rendered identically. Core merges into the same nested criteria, and
        // adding an association cannot affect the B2B filter.
        $criteria->addAssociation('documents.documentType');

        // No customer filter of our own. The route applies the one that is correct for this shop —
        // see the class docblock. Adding one here would at best duplicate it and at worst disagree.
        //
        // The REAL request, not a fresh one. Core 6.7 reads only `checkPromotion` from it, so an
        // empty `Request` works today — but the decorator this whole argument rests on is a
        // third-party class outside this repository, and handing it an object with no session, no
        // client IP and no attributes is betting on what it does not read. The fallback covers the
        // console, where there is no request and no shopper either.
        $result = $this->orderRoute->load(
            $this->requests->getMainRequest() ?? new Request(),
            $this->contexts->current(),
            $criteria,
        );

        $summaries = [];

        foreach ($result->getOrders() as $order) {
            if ($order instanceof OrderEntity) {
                $summaries[] = $this->mapper->summarise($order);
            }
        }

        return $summaries;
    }
}
