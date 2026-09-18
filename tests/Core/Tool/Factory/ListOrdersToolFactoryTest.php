<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ListOrdersToolFactory;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\LoopOnlyGateway;
use Swag\AssistantStarterKit\Tests\Core\Commerce\OrderCapableGateway;

/**
 * Three gates, and every one of them means "never constructed" rather than "refused when called".
 *
 * That is D6, and the guest gate is where it earns its keep: a guest's model never receives this
 * tool in its schema, so there is no instruction to disobey and nothing to talk past. A version that
 * built the tool and checked the shopper inside `__invoke()` would be one prompt away from a
 * different outcome.
 */
final class ListOrdersToolFactoryTest extends TestCase
{
    public function testBuildsWhenToggleOnGatewayCapableAndSignedIn(): void
    {
        self::assertInstanceOf(
            ListOrdersTool::class,
            (new ListOrdersToolFactory())->create(self::context(on: true, capable: true, loggedIn: true)),
        );
    }

    public function testRefusesWhenTheMerchantHasNotSwitchedItOn(): void
    {
        self::assertNull((new ListOrdersToolFactory())->create(self::context(
            on: false,
            capable: true,
            loggedIn: true,
        )));
    }

    public function testRefusesWhenTheGatewayCannotReadOrders(): void
    {
        self::assertNull((new ListOrdersToolFactory())->create(self::context(
            on: true,
            capable: false,
            loggedIn: true,
        )));
    }

    public function testRefusesForAGuest(): void
    {
        self::assertNull((new ListOrdersToolFactory())->create(self::context(
            on: true,
            capable: true,
            loggedIn: false,
        )));
    }

    /**
     * A caller that never says who is shopping gets no tool.
     *
     * `loggedIn` defaults to false on the context, so the probe command and the eval harness — which
     * run outside a storefront request and have no shopper at all — fail closed without having to
     * remember anything.
     */
    public function testRefusesWhenTheCallerNeverSaidWhoIsShopping(): void
    {
        $gateway = new OrderCapableGateway();

        $context = new GroundedToolContext(
            gateway: $gateway,
            trace: new TraceRecorder(),
            config: new AssistantConfig(enableOrderHistory: true),
            renderer: new FactRenderer(new TraceRecorder()),
            facetProbe: new FacetProbe($gateway, new TraceRecorder()),
            blocklist: new BlocklistFilter(),
            variantResolver: new VariantResolver($gateway, new TraceRecorder()),
            queryBuilder: new QueryBuilder(),
            cartAvailable: false,
        );

        self::assertNull((new ListOrdersToolFactory())->create($context));
    }

    private static function context(bool $on, bool $capable, bool $loggedIn): GroundedToolContext
    {
        $gateway = self::gateway($capable);

        return new GroundedToolContext(
            gateway: $gateway,
            trace: new TraceRecorder(),
            config: new AssistantConfig(enableOrderHistory: $on),
            renderer: new FactRenderer(new TraceRecorder()),
            facetProbe: new FacetProbe($gateway, new TraceRecorder()),
            blocklist: new BlocklistFilter(),
            variantResolver: new VariantResolver($gateway, new TraceRecorder()),
            queryBuilder: new QueryBuilder(),
            cartAvailable: false,
            orderRenderer: new OrderRenderer(),
            loggedIn: $loggedIn,
        );
    }

    /**
     * `LoopOnlyGateway` implements only the required interface, which is exactly "a merchant's own
     * gateway that never heard of order history" — so the incapable case costs no second double.
     */
    private static function gateway(bool $capable): CommerceGatewayInterface
    {
        $capableGateway = new OrderCapableGateway();

        return $capable ? $capableGateway : new LoopOnlyGateway($capableGateway);
    }
}
