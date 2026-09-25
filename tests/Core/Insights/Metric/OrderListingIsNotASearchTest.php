<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights\Metric;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\ToolResultShape;
use Swag\AssistantStarterKit\Core\Grounding\OrderRenderer;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\Metric\SearchesByTurn;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Core\Commerce\RecordingOrderHistory;

/**
 * A turn that listed the shopper's orders is not a catalogue search.
 *
 * {@see SearchesByTurn} marks a product search by an integer `total` on `tool.result`, and
 * {@see ToolResultShape} records `total` by value from whatever tool returned it. `list_orders`
 * returned its count as `total` until 2026-09-24, so every order listing entered the insights as a
 * search — in the denominator of every search ratio, and as a "found nothing" turn with an empty term
 * whenever the shopper had no orders.
 *
 * Built from the tool's real return value rather than a hand-written payload, so renaming the count
 * back to `total` fails here.
 */
final class OrderListingIsNotASearchTest extends TestCase
{
    public function testAnOrderListingAddsNoSearchTurn(): void
    {
        $result = (new ListOrdersTool(new RecordingOrderHistory(2), new OrderRenderer(), new TraceRecorder()))();

        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable('2026-09-24 10:00:00'),
            [
                [
                    'seq' => 1,
                    'stage' => 'tool.result',
                    'payload' => ['name' => 'list_orders', ...ToolResultShape::of($result)],
                ],
                ['seq' => 2, 'stage' => 'turn.end', 'payload' => ['outcome' => 'orders_shown']],
            ],
            [],
        );

        self::assertSame([], SearchesByTurn::in($trace));
    }
}
