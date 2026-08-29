<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Context;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingContextResolver;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nothing here constructs a real `SalesChannelContext` — its constructor takes a dozen
 * collaborators none of these cases care about, so it is mocked the same way
 * {@see \Swag\AssistantStarterKit\Tests\Controller\AssistantEndpointTestCase} does. The provider is
 * driven through its `use()` callback so no request is needed.
 */
final class ShoppingContextResolverTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    private function resolveWith(?string $customerId): ShoppingContext
    {
        $customer = null;
        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn($customer);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::CHANNEL);

        $provider = new SalesChannelContextProvider(new RequestStack());

        return $provider->use($salesChannelContext, static fn(): ShoppingContext => (new ShoppingContextResolver(
            $provider,
        ))->current());
    }

    public function testNoCustomerResolvesAsGuest(): void
    {
        $context = $this->resolveWith(null);

        self::assertSame(ShoppingMode::Guest, $context->mode);
        self::assertNull($context->customerId);
        self::assertSame(self::CHANNEL, $context->salesChannelId);
    }

    public function testALoggedInCustomerResolvesAsThatCustomer(): void
    {
        $context = $this->resolveWith(self::ALICE);

        self::assertSame(ShoppingMode::Customer, $context->mode);
        self::assertSame(self::ALICE, $context->customerId);
    }

    public function testTheCommercialFieldsAreNullBecauseNothingResolvesThemYet(): void
    {
        // Pinned so the Commercial bridge has to change this test deliberately rather than by
        // accident, and so nobody reads a null here as "not implemented" when it means "no B2B".
        $context = $this->resolveWith(self::ALICE);

        self::assertNull($context->employeeId);
        self::assertNull($context->organisationId);
    }
}
