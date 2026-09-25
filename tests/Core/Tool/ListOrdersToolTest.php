<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\RecordingOrderHistory;

/**
 * The tool returns each order with the figures its card shows — and nothing that is not on the card.
 *
 * It returned order NUMBERS only until 2026-09-24, when D3 was relaxed for the shopper's own orders:
 * testers asked for the status and the total of orders the card was already showing, and a model
 * handed no figure could only refuse or escalate. The return shape is still asserted by key, not
 * merely by content, because the keys are now the whole of what that relaxation allows — a document
 * URL or a field about a person arriving here would widen it without anyone deciding to.
 */
final class ListOrdersToolTest extends TestCase
{
    /**
     * `orderCount`, not `total`: beside each order's own `total` a bare `total` reads as money, and a
     * model asked "how much did I spend?" would have had a count to misread as the answer.
     */
    public function testReturnsEachOrderWithTheFiguresItsCardShows(): void
    {
        $tool = new ListOrdersTool(self::reader(2), new OrderRenderer(), new TraceRecorder());

        $result = $tool();

        self::assertSame(['orders', 'withDocuments', 'orderCount', 'filtered'], array_keys($result));
        self::assertSame(
            [
                'orderNumber' => '10000',
                'orderedAt' => '2026-09-12',
                'state' => 'Shipped',
                'total' => 10.0,
                'currency' => 'EUR',
                'itemCount' => 1,
            ],
            $result['orders'][0] ?? null,
        );
        self::assertSame(['10000', '10001'], array_column($result['orders'], 'orderNumber'));
        self::assertSame(2, $result['orderCount']);
        self::assertFalse($result['filtered'], 'nothing was narrowed');
    }

    /**
     * The document stays a yes/no. Its URL is what the server-rendered link on the card exists to keep
     * out of the model's context, and the relaxation of D3 covers figures, not links.
     */
    public function testNoDocumentLinkReachesTheModel(): void
    {
        $encoded = (string) json_encode(
            (new ListOrdersTool(self::readerWithInvoiceOnFirst(), new OrderRenderer(), new TraceRecorder()))(),
        );

        self::assertStringNotContainsString('/account/order/document', $encoded);
        self::assertStringNotContainsString('Invoice', $encoded);
    }

    public function testRegistersWhatItRetrieved(): void
    {
        $renderer = new OrderRenderer();

        (new ListOrdersTool(self::reader(1), $renderer, new TraceRecorder()))();

        self::assertSame(
            ['10000'],
            array_map(static fn($order): string => $order->orderNumber, $renderer->retrievedOrders()),
        );
    }

    /**
     * Bounds live in the method body, not in the schema.
     *
     * `#[AsTool]` derives the JSON Schema from the signature by reflection, so `minimum` and
     * `maximum` cannot be declared — the same regression against a hand-written schema that every
     * other tool here closes with a guard clause.
     */
    /**
     * Bounds live in the method body, not in the schema.
     *
     * `#[AsTool]` derives the JSON Schema from the signature by reflection, so `minimum` and
     * `maximum` cannot be declared — the same regression against a hand-written schema that every
     * other tool here closes with a guard clause.
     *
     * One reader per case rather than one reused across three: the bound is recorded on a mutable
     * property, and reusing the instance lets a static analyser narrow it to the first value it saw
     * and call the later assertions impossible.
     */
    #[DataProvider('limits')]
    public function testClampsTheLimitItAsksFor(?int $asked, int $expected): void
    {
        $reader = new RecordingOrderHistory(50);

        (new ListOrdersTool($reader, new OrderRenderer(), new TraceRecorder()))($asked);

        self::assertSame($expected, $reader->askedFor);
    }

    /** @return array<string, array{?int, int}> */
    public static function limits(): array
    {
        return [
            'no limit given' => [null, 5],
            'above the ceiling' => [99, 10],
            'below the floor' => [0, 1],
            'negative' => [-5, 1],
        ];
    }

    /**
     * Which orders carry a document, and nothing more about them.
     *
     * The model has to be able to answer "show me my invoices" truthfully — which orders have one,
     * and that none do when none do. Without this it can only list orders and hope the cards happen
     * to carry a link, which is answering a different question.
     */
    public function testNamesWhichOrdersCarryADocument(): void
    {
        $result = (new ListOrdersTool(self::readerWithInvoiceOnFirst(), new OrderRenderer(), new TraceRecorder()))();

        self::assertSame(['10000', '10001'], array_column($result['orders'], 'orderNumber'));
        self::assertSame(['10000'], $result['withDocuments']);
    }

    public function testSaysSoWhenNoOrderCarriesADocument(): void
    {
        $result = (new ListOrdersTool(self::reader(2), new OrderRenderer(), new TraceRecorder()))();

        self::assertSame([], $result['withDocuments']);
    }

    /** The trace is what {@see \Swag\AssistantStarterKit\Eval\Assertion\NoForeignOrderInProse} reads. */
    public function testRecordsWhatItFetched(): void
    {
        $trace = new TraceRecorder();

        (new ListOrdersTool(self::reader(2), new OrderRenderer(), $trace))();

        self::assertSame(['10000', '10001'], $trace->payload('orders.listed')['orderNumbers'] ?? null);
    }

    private static function reader(int $count): RecordingOrderHistory
    {
        return new RecordingOrderHistory($count);
    }

    private static function readerWithInvoiceOnFirst(): RecordingOrderHistory
    {
        return new RecordingOrderHistory(2, withDocumentOnFirst: true);
    }
}
