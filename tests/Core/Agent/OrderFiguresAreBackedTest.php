<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\EvalOrders;
use Swag\AssistantStarterKit\Tests\Support\BuildsChatResponses;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A figure an order tool returned this turn is backed; a price nothing returned still is not.
 *
 * The price audit (`FactRenderer::unbackedPricesInProse()`) measures every currency figure in the
 * reply against the RENDERED product cards. An order turn renders none, so once D3 was relaxed for the
 * shopper's own orders on 2026-09-24, every correct "your order came to €118.44" became a
 * `claims.audit` entry and a `no_unbacked_price_in_prose` failure — a control firing on correct
 * behaviour, which ruling R85 says trains people to ignore it. Nothing is stripped from the reply
 * (the audit records, it does not rewrite), so the cost was noise in exactly the place a merchant
 * looks for invented prices.
 *
 * Driven through the real processor, because the wiring is where this class of fix dies quietly.
 */
final class OrderFiguresAreBackedTest extends TestCase
{
    use BuildsChatResponses;
    use UsesCatalogFixture;

    private const ORDER_REPLY =
        'Your order 10023 from 12.09.2026 is Shipped and came to €118.44: '
            . '3 × Chain Oil 100ml at €12.90 each (€38.70) and 1 × Brake Pads at 79,74 €. '
            . 'Order 10019 is Open, 683.00 EUR, 4 × Trail Helmet.';

    public function testFiguresAnOrderToolReturnedAreNotUnbackedPrices(): void
    {
        [$renderer, $trace] = self::ground(self::ORDER_REPLY, self::ordersRetrieved());

        self::assertSame([], $renderer->unbackedPrices());
        self::assertSame([], self::discarded($trace));
    }

    public function testAnInventedPriceBesideThemIsStillCaught(): void
    {
        [$renderer] = self::ground(self::ORDER_REPLY . ' The helmet you looked at is €99.99.', self::ordersRetrieved());

        self::assertSame(['99.99'], $renderer->unbackedPrices());
    }

    /**
     * "Exactly as returned" is the boundary in the prompt, and this is where it is measured: a sum is
     * arithmetic of the model's own, and no tool returned it.
     */
    public function testATotalTheModelAddedUpItselfIsStillUnbacked(): void
    {
        [$renderer] = self::ground('Together they came to €801.44.', self::ordersRetrieved());

        self::assertSame(['801.44'], $renderer->unbackedPrices());
    }

    /** Order figures back a reply only on the turn that retrieved them — never the catalogue's. */
    public function testATurnThatRetrievedNoOrderBacksNoOrderFigure(): void
    {
        [$renderer] = self::ground('Your order came to €118.44.', new OrderRenderer());

        self::assertSame(['118.44'], $renderer->unbackedPrices());
    }

    /**
     * The shipped pipeline end to end: `list_orders` runs, the reply quotes its totals, and the turn's
     * warnings stay empty. Fails if the factory ever stops handing the processor the turn's own
     * `OrderRenderer` — the tests above construct the processor by hand and cannot see that.
     */
    public function testTheShippedPipelineBacksWhatListOrdersReturned(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $gateway->seedOrders(EvalOrders::two());
        $config = new AssistantConfig(enableOrderHistory: true);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(new MockHttpClient([
            self::toolCallResponse('list_orders', []),
            self::textResponse('Order 10023 is Shipped and came to €118.44; order 10019 is Open, at €73.08.'),
        ]))->create($gateway, $config, false, new LlmSettings('https://1.1.1.1', 'key', 'gpt-x'), loggedIn: true);

        $turn = (new AssistantRunner($config, $bundle))->run('what did my last orders cost?', new MessageBag());

        self::assertSame(
            ['10023', '10019'],
            array_map(static fn(OrderSummary $order): string => $order->orderNumber, $turn->orders),
        );
        self::assertSame([], $turn->warnings->unbackedPrices);
    }

    private static function ordersRetrieved(): OrderRenderer
    {
        $orders = new OrderRenderer();
        $orders->registerRetrieved([
            new OrderSummary('10023', new \DateTimeImmutable('2026-09-12'), 'Shipped', 118.44, 'EUR', 4, []),
            new OrderSummary('10019', new \DateTimeImmutable('2026-09-08'), 'Open', 683.0, 'EUR', 4, []),
        ]);
        $orders->registerDetail(new OrderDetail(
            orderNumber: '10023',
            orderedAt: new \DateTimeImmutable('2026-09-12'),
            stateLabel: 'Shipped',
            total: 118.44,
            currency: 'EUR',
            lines: [new OrderLine('Chain Oil 100ml', 3, 12.90, 38.70), new OrderLine('Brake Pads', 1, 79.74, 79.74)],
            documents: [],
        ));

        return $orders;
    }

    /** @return array{FactRenderer, TraceRecorder} */
    private static function ground(string $prose, OrderRenderer $orders): array
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        (new GroundingOutputProcessor($renderer, $trace, orders: $orders))->processOutput(
            new Output('gpt-x', new TextResult($prose), new MessageBag()),
        );

        return [$renderer, $trace];
    }

    /** @return list<mixed> */
    private static function discarded(TraceRecorder $trace): array
    {
        return array_values(array_filter(array_map(
            static fn(TraceEvent $event): mixed => $event->payload['modelClaimsDiscarded'] ?? null,
            array_filter($trace->events(), static fn(TraceEvent $event): bool => $event->stage === 'claims.audit'),
        )));
    }
}
