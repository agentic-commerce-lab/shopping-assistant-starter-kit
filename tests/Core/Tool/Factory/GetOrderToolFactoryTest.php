<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\Factory\GetOrderToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Tool\GetOrderTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\LoopOnlyGateway;
use Swag\AssistantStarterKit\Tests\Core\Commerce\OrderCapableGateway;

/**
 * The same three gates as `list_orders`, asserted separately rather than assumed shared.
 *
 * Two tools behind one switch is a decision, and a decision that holds only because both files
 * happen to read the same property is a decision nothing protects. If one factory ever stops
 * checking `loggedIn`, this is what says so.
 */
final class GetOrderToolFactoryTest extends TestCase
{
    public function testBuildsWhenToggleOnGatewayCapableAndSignedIn(): void
    {
        self::assertInstanceOf(
            GetOrderTool::class,
            (new GetOrderToolFactory())->create(self::context(on: true, capable: true, loggedIn: true)),
        );
    }

    /** @param array{bool, bool, bool} $gates */
    #[DataProvider('closedGates')]
    public function testRefusesUnlessEveryGateIsOpen(bool $on, bool $capable, bool $loggedIn): void
    {
        self::assertNull((new GetOrderToolFactory())->create(self::context(
            on: $on,
            capable: $capable,
            loggedIn: $loggedIn,
        )));
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function closedGates(): array
    {
        return [
            'merchant has not switched it on' => [false, true, true],
            'gateway cannot read orders' => [true, false, true],
            'shopper is a guest' => [true, true, false],
        ];
    }

    private static function context(bool $on, bool $capable, bool $loggedIn): GroundedToolContext
    {
        $capableGateway = new OrderCapableGateway();
        $gateway = $capable ? $capableGateway : new LoopOnlyGateway($capableGateway);

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
}
