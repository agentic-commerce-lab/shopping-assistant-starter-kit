<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;

/**
 * One Shopware order entity, as the card shows it.
 *
 * Split out of {@see DalOrderHistory} beside {@see DalProductCardMapper} and for the same reason: a
 * Shopware entity needs null handling at every accessor, and that alone put the reading class over
 * mago's complexity threshold. What is left there is the one thing worth reading in one place —
 * which service the orders come from.
 *
 * **Nothing about the shopper crosses this boundary.** The route force-associates `billingAddress`
 * and `orderCustomer.customer`; none of it is read here, and {@see OrderSummary} has no field that
 * could hold it.
 */
final readonly class DalOrderMapper
{
    public function __construct(
        private DalOrderDocuments $documents,
    ) {}

    public function summarise(OrderEntity $order): OrderSummary
    {
        $lineItems = $order->getLineItems() ?? new OrderLineItemCollection();

        return new OrderSummary(
            orderNumber: $order->getOrderNumber() ?? '',
            orderedAt: \DateTimeImmutable::createFromInterface($order->getOrderDateTime()),
            // Shopware's own translated label, carried rather than derived: mapping the state machine
            // ourselves would give the card a vocabulary the shopper's account page does not use.
            stateLabel: (string) ($order->getStateMachineState()?->getTranslation('name') ?? ''),
            total: $order->getAmountTotal(),
            currency: $order->getCurrency()?->getIsoCode() ?? '',
            itemCount: $lineItems->count(),
            documents: $this->documents->of($order),
        );
    }
}
