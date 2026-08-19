<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

final class TraceRecorder
{
    /** @var list<TraceEvent> */
    private array $events = [];

    private int $seq = 0;

    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $stage, array $payload): void
    {
        $this->events[] = new TraceEvent($this->seq, $stage, $payload);
        $this->seq++;
    }

    /**
     * @return list<TraceEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function payload(string $stage): ?array
    {
        // Scan backwards to find the last event with this stage
        foreach (\array_reverse($this->events) as $event) {
            if ($event->stage === $stage) {
                return $event->payload;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function stages(): array
    {
        $stages = [];
        $seen = [];

        foreach ($this->events as $event) {
            if ($seen[$event->stage] ?? false) {
                continue;
            }

            $stages[] = $event->stage;
            $seen[$event->stage] = true;
        }

        return $stages;
    }
}
