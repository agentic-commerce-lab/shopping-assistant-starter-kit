<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderSummary;
use Swag\AssistantStarterKit\Core\Commerce\OrderHistoryReader;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

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

        self::assertSame(['orderNumbers', 'total'], array_keys($result));
        self::assertSame(['10000', '10001'], $result['orderNumbers']);
        self::assertSame(2, $result['total']);
    }

    public function testRegistersWhatItRetrieved(): void
    {
        $renderer = new OrderRenderer();

        (new ListOrdersTool(self::reader(1), $renderer, new TraceRecorder()))();

        self::assertCount(1, $renderer->retrievedOrders());
        self::assertSame('10000', $renderer->retrievedOrders()[0]->orderNumber);
    }

    /**
     * Bounds live in the method body, not in the schema.
     *
     * `#[AsTool]` derives the JSON Schema from the signature by reflection, so `minimum` and
     * `maximum` cannot be declared — the same regression against a hand-written schema that every
     * other tool here closes with a guard clause.
     */
    public function testDefaultsToFiveAndClampsBothEnds(): void
    {
        $reader = self::reader(50);
        $tool = new ListOrdersTool($reader, new OrderRenderer(), new TraceRecorder());

        $tool();
        self::assertSame(5, $reader->askedFor, 'no limit given');

        $tool(99);
        self::assertSame(10, $reader->askedFor, 'above the ceiling');

        $tool(0);
        self::assertSame(1, $reader->askedFor, 'below the floor');
    }

    /** The trace is what {@see \Swag\AssistantStarterKit\Eval\Assertion\NoForeignOrderInProse} reads. */
    public function testRecordsWhatItFetched(): void
    {
        $trace = new TraceRecorder();

        (new ListOrdersTool(self::reader(2), new OrderRenderer(), $trace))();

        self::assertSame(['10000', '10001'], $trace->payload('orders.listed')['orderNumbers'] ?? null);
    }

    private static function reader(int $count): OrderHistoryReader
    {
        return new class($count) implements OrderHistoryReader {
            public int $askedFor = 0;

            public function __construct(
                private readonly int $count,
            ) {}

            public function orders(int $limit): array
            {
                $this->askedFor = $limit;
                $orders = [];

                for ($index = 0; $index < min($this->count, $limit); ++$index) {
                    $orders[] = new OrderSummary(
                        orderNumber: (string) (10000 + $index),
                        orderedAt: new \DateTimeImmutable('2026-09-12'),
                        stateLabel: 'Shipped',
                        total: 10.0,
                        currency: 'EUR',
                        itemCount: 1,
                        documents: [],
                    );
                }

                return $orders;
            }
        };
    }
}
