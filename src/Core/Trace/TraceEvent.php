<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

final readonly class TraceEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param int                  $elapsedMs milliseconds from the start of the turn to when this
     *                                        event was recorded — a point on a timeline, never a
     *                                        span. See {@see TraceRecorder} for why.
     */
    public function __construct(
        public int $seq,
        public string $stage,
        public array $payload,
        public int $elapsedMs = 0,
    ) {}
}
