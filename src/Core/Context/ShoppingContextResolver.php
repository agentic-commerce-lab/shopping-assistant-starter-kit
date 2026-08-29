<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;

/**
 * Turns a sales-channel context into a {@see ShoppingContext}.
 *
 * The only class in this namespace that touches Shopware. {@see self::current()} does so through
 * {@see SalesChannelContextProvider} rather than a `RequestStack` of its own — that provider's
 * docblock claims to be the single place allowed to reach for the request context, and a second
 * reader would quietly make that false. {@see self::of()} takes a `SalesChannelContext` directly and
 * touches nothing Shopware-request-shaped at all.
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
        return $this->of($this->contexts->current());
    }

    /**
     * The pure mapping — no request lookup, no provider override.
     *
     * A caller that already holds a `SalesChannelContext` (a storefront controller Shopware injected
     * one into, for instance) should call this rather than routing through
     * {@see SalesChannelContextProvider}'s `use()` escape hatch for a context it did not need to
     * fetch in the first place: that escape hatch exists for callers with no request at all (a
     * console command), not for one that already has the exact context it needs.
     */
    public function of(SalesChannelContext $context): ShoppingContext
    {
        $customerId = $context->getCustomer()?->getId();

        return new ShoppingContext(
            mode: $customerId === null ? ShoppingMode::Guest : ShoppingMode::Customer,
            salesChannelId: $context->getSalesChannelId(),
            customerId: $customerId,
        );
    }
}
