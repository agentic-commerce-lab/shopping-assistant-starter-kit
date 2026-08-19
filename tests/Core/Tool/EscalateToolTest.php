<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class EscalateToolTest extends TestCase
{
    public function testRecordsTheReasonAndSignalsHandover(): void
    {
        $trace = new TraceRecorder();

        $result = (new EscalateTool($trace))(reason: 'Order status is not available to me.');

        self::assertTrue($result['escalated']);
        self::assertSame('Order status is not available to me.', $trace->payload('escalate')['reason']);
    }
}
