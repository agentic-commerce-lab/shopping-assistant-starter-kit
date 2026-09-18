<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Tool\GetOrderTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\OrderCapableGateway;

/**
 * The tool names what was in an order and counts nothing.
 *
 * D3 applied where it is easiest to forget: "you ordered three of them" is a figure, and a figure the
 * model states is a figure it can state wrongly. The quantity, the unit price and the line total are
 * on the card the server rendered beside the reply — so the return shape is asserted by key, not by
 * content, because widening it is how the guarantee would end without anyone deciding to end it.
 */
final class GetOrderToolTest extends TestCase
{
    public function testReturnsLineNamesAndNoFigures(): void
    {
        $result = self::tool()('10023');

        self::assertSame(['orderNumber', 'items', 'found'], array_keys($result));
        self::assertSame(['Chain Oil 100ml', 'Brake Pads'], $result['items']);
        self::assertTrue($result['found']);
    }

    public function testRegistersTheDetailItRetrieved(): void
    {
        $renderer = new OrderRenderer();

        self::tool($renderer)('10023');

        self::assertSame('10023', $renderer->retrievedDetail()?->orderNumber);
    }

    /**
     * An order this shopper cannot see, and one that never existed, are the same answer.
     *
     * The gateway returns null for both — see `OrderHistoryReader::order()` on why distinguishing
     * them would tell a shopper that somebody else's order exists.
     */
    public function testReportsNotFoundWithoutSayingWhy(): void
    {
        $renderer = new OrderRenderer();

        $result = self::tool($renderer)('99999');

        self::assertFalse($result['found']);
        self::assertSame([], $result['items']);
        self::assertNull($renderer->retrievedDetail(), 'nothing may be rendered for an order we did not get');
    }

    /** `#[AsTool]` cannot express `maxLength`, so the guard is a clause in the body. */
    public function testRefusesAnAbsurdlyLongArgumentWithoutAskingTheGateway(): void
    {
        $result = self::tool()(str_repeat('9', 300));

        self::assertFalse($result['found']);
    }

    public function testRecordsWhatItLookedUp(): void
    {
        $trace = new TraceRecorder();

        self::tool(new OrderRenderer(), $trace)('10023');

        self::assertSame('10023', $trace->payload('orders.detail')['orderNumber'] ?? null);
        self::assertTrue($trace->payload('orders.detail')['found'] ?? null);
    }

    private static function tool(?OrderRenderer $renderer = null, ?TraceRecorder $trace = null): GetOrderTool
    {
        $detail = new OrderDetail(
            orderNumber: '10023',
            orderedAt: new \DateTimeImmutable('2026-09-12'),
            stateLabel: 'Shipped',
            total: 118.44,
            currency: 'EUR',
            lines: [
                new OrderLine('Chain Oil 100ml', 3, 12.90, 38.70),
                new OrderLine('Brake Pads', 1, 79.74, 79.74),
            ],
            documents: [],
        );

        return new GetOrderTool(
            new OrderCapableGateway([], [$detail]),
            $renderer ?? new OrderRenderer(),
            $trace ?? new TraceRecorder(),
        );
    }
}
