<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

final readonly class TraceEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $seq,
        public string $stage,
        public array $payload,
    ) {}
}
