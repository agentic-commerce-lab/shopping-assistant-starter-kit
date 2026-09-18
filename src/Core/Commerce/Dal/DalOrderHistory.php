<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

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
 */
final readonly class DalOrderHistory implements OrderHistoryReader
{
    public function __construct(
        private AbstractOrderRoute $orderRoute,
        private SalesChannelContextProvider $contexts,
        private RouterInterface $router,
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

        // No customer filter of our own. The route applies the one that is correct for this shop —
        // see the class docblock. Adding one here would at best duplicate it and at worst disagree.
        $result = $this->orderRoute->load(new Request(), $this->contexts->current(), $criteria);

        $summaries = [];

        foreach ($result->getOrders() as $order) {
            if ($order instanceof OrderEntity) {
                $summaries[] = $this->summarise($order);
            }
        }

        return $summaries;
    }

    private function summarise(OrderEntity $order): OrderSummary
    {
        $lineItems = $order->getLineItems() ?? new OrderLineItemCollection();

        return new OrderSummary(
            id: $order->getId(),
            orderNumber: $order->getOrderNumber() ?? '',
            orderedAt: \DateTimeImmutable::createFromInterface($order->getOrderDateTime()),
            // Shopware's own translated label, carried rather than derived: mapping the state machine
            // ourselves would give the card a vocabulary the shopper's account page does not use.
            stateLabel: $order->getStateMachineState()?->getTranslation('name') ?? '',
            total: $order->getAmountTotal(),
            currency: $order->getCurrency()?->getIsoCode() ?? '',
            itemCount: $lineItems->count(),
            documents: $this->documents($order),
        );
    }

    /** @return list<OrderDocumentRef> */
    private function documents(OrderEntity $order): array
    {
        $refs = [];

        foreach ($order->getDocuments() ?? [] as $document) {
            $deepLinkCode = $document->getDeepLinkCode();

            if ($deepLinkCode === null) {
                continue;
            }

            $refs[] = new OrderDocumentRef(
                title: $document->getDocumentType()?->getTranslation('name') ?? 'Document',
                // Built from the route, server-side, and never handed to the model — the same rule as
                // the checkout link and the contact URL (D3). That route is login-required and
                // re-authenticates, so a copied link is a link the browser still has to earn.
                url: $this->router->generate(
                    'frontend.account.order.single.document',
                    ['documentId' => $document->getId(), 'deepLinkCode' => $deepLinkCode],
                    UrlGeneratorInterface::ABSOLUTE_PATH,
                ),
                extension: 'pdf',
            );
        }

        return $refs;
    }
}
