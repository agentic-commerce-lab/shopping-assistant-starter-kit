<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;

/**
 * Turns the request's sales-channel context into a {@see ShoppingContext}.
 *
 * The only class in this namespace that touches Shopware, and it does so through
 * {@see SalesChannelContextProvider} rather than a `RequestStack` of its own — that provider's
 * docblock claims to be the single place allowed to reach for the request context, and a second
 * reader would quietly make that false.
 *
 * Absence of Shopware Commercial is an ordinary supported state, not an error: a shop without it has
 * guests and customers, and both are fully served. The employee and organisation fields stay null
 * until the Commercial bridge exists.
 */
final readonly class ShoppingContextResolver
{
    public function __construct(
        private SalesChannelContextProvider $contexts,
    ) {}

    public function current(): ShoppingContext
    {
        $context = $this->contexts->current();
        $customerId = $context->getCustomer()?->getId();

        return new ShoppingContext(
            mode: $customerId === null ? ShoppingMode::Guest : ShoppingMode::Customer,
            salesChannelId: $context->getSalesChannelId(),
            customerId: $customerId,
        );
    }
}
