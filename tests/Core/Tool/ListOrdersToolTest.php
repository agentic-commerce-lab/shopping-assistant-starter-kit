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
 * The tool returns order NUMBERS and nothing else.
 *
 * That is D3 applied to a second kind of record: a total, a date and a state label are figures, and
 * the rule that the model never supplies a figure is the same one that keeps it from inventing a
 * price. The return shape is asserted by key, not merely by content, because widening it is how the
 * guarantee would end without anyone deciding to end it.
 */
final class ListOrdersToolTest extends TestCase
{
    public function testReturnsOrderNumbersAndNoFigures(): void
    {
        $tool = new ListOrdersTool(self::reader(2), new OrderRenderer(), new TraceRecorder());

        $result = $tool();

        self::assertSame(['orderNumbers', 'withDocuments', 'total', 'filtered'], array_keys($result));
        self::assertSame(['10000', '10001'], $result['orderNumbers']);
        self::assertSame(2, $result['total']);
        self::assertFalse($result['filtered'], 'nothing was narrowed');
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
     *
     * A boolean about attachment is not a figure in D3's sense: it is the same class as `total`,
     * which says how many orders are being returned. The title, the file and the URL stay out — a
     * URL in the model's context is the one thing the server-rendered link exists to avoid.
     */
    public function testNamesWhichOrdersCarryADocument(): void
    {
        $result = (new ListOrdersTool(self::readerWithInvoiceOnFirst(), new OrderRenderer(), new TraceRecorder()))();

        self::assertSame(['10000', '10001'], $result['orderNumbers']);
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
