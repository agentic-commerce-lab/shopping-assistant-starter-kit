<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Sink;

use Swag\AssistantStarterKit\Core\Trace\Sink\TraceSinkInterface;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/** A third-party integration having a bad day. */
final class ExplodingTraceSink implements TraceSinkInterface
{
    public function send(#[\SensitiveParameter] string $token, string $salesChannelId, TraceRecorder $trace): void
    {
        throw new \RuntimeException('sink is down');
    }
}
