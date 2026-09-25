<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDocumentRef;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderLine;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Tool\GetOrderTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\OrderCapableGateway;

/**
 * The tool returns what was in an order with the figures the card shows beside it.
 *
 * It returned line NAMES only until 2026-09-24. A tester asked to order the same again and got one of
 * each, because a model that was never given a quantity had to assume one — D3 kept it from stating a
 * wrong figure by making it act on an invented one instead. D3 now yields for the shopper's own
 * orders. The shape is still asserted by key, not by content: those keys are the whole of what the
 * relaxation allows, and the document URL beside them must never become one.
 */
final class GetOrderToolTest extends TestCase
{
    public function testReturnsTheOrderWithItsLineFigures(): void
    {
        $result = self::tool()('10023');

        self::assertSame(
            ['orderNumber', 'orderedAt', 'state', 'total', 'currency', 'lines', 'found'],
            array_keys($result),
        );
        self::assertSame(['2026-09-12', 'Shipped', 118.44, 'EUR'], [
            $result['orderedAt'] ?? null,
            $result['state'] ?? null,
            $result['total'] ?? null,
            $result['currency'] ?? null,
        ]);
        self::assertSame(
            [
                ['name' => 'Chain Oil 100ml', 'quantity' => 3, 'unitPrice' => 12.90, 'lineTotal' => 38.70],
                ['name' => 'Brake Pads', 'quantity' => 1, 'unitPrice' => 79.74, 'lineTotal' => 79.74],
            ],
            $result['lines'],
        );
        self::assertTrue($result['found']);
    }

    /** Figures, not links: the invoice's URL stays on the card the server renders. */
    public function testNoDocumentLinkReachesTheModel(): void
    {
        $encoded = (string) json_encode(self::tool()('10023'));

        self::assertStringNotContainsString('/account/order/document', $encoded);
        self::assertStringNotContainsString('Invoice', $encoded);
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

        self::assertSame(['orderNumber' => '99999', 'lines' => [], 'found' => false], $result);
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
            documents: [new OrderDocumentRef('Invoice', '/account/order/document/abc/def', 'pdf')],
        );

        return new GetOrderTool(
            new OrderCapableGateway([], [$detail]),
            $renderer ?? new OrderRenderer(),
            $trace ?? new TraceRecorder(),
        );
    }
}
