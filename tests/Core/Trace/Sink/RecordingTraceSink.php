<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Sink;

use Swag\AssistantStarterKit\Core\Trace\Sink\TraceSinkInterface;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/** A sink that remembers what it was handed. */
final class RecordingTraceSink implements TraceSinkInterface
{
    public int $calls = 0;

    public ?string $lastToken = null;

    public ?string $lastSalesChannelId = null;

    public ?TraceRecorder $lastTrace = null;

    public function send(#[\SensitiveParameter] string $token, string $salesChannelId, TraceRecorder $trace): void
    {
        $this->calls++;
        $this->lastToken = $token;
        $this->lastSalesChannelId = $salesChannelId;
        $this->lastTrace = $trace;
    }
}
