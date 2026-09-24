<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\AssertionRegistry;
use Swag\AssistantStarterKit\Eval\Assertion\NotEscalated;

final class NotEscalatedTest extends TestCase
{
    public function testPassesWhenTheRunAnsweredFromTheOrderTools(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.listed', ['orderNumbers' => ['10023', '10019']]);
        $trace->record('turn.end', ['outcome' => 'orders_shown']);

        $result = (new NotEscalated())->evaluate(
            new AssistantTurn('10023 is Shipped.', [], 'orders_shown'),
            $trace,
            [],
        );

        self::assertTrue($result->passed);
    }

    /** The staging defect: the orders were listed, and the turn handed off anyway. */
    public function testFailsWhenTheRunListedTheOrdersAndEscalatedAnyway(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.listed', ['orderNumbers' => ['10023', '10019']]);
        $trace->record('escalate', [
            'reason' => 'order status details require human support',
            'hasDestination' => true,
        ]);

        $result = AssertionRegistry::resolve('not_escalated', 'order_status_answered')->evaluate(
            new AssistantTurn('I cannot help with that.', [], 'escalated'),
            $trace,
            [],
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('order status details require human support', $result->detail);
    }
}
