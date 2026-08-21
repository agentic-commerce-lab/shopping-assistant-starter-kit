<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

/**
 * Collects one turn's pipeline events.
 *
 * **Timing is an offset, never a duration.** `record()` is an *entry* marker at some call sites
 * ({@see \Swag\AssistantStarterKit\Core\Agent\BoundedToolbox::execute()} records before the tool
 * runs) and a *completion* marker at others ({@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool}
 * records after `gateway->search()` returns). A `durationMs` computed as the gap to the next event
 * would therefore mean a different thing per stage — model latency in one row, tool execution in
 * the next — with no way for a reader to tell which.
 *
 * Ruling R62 refused such a column because it could only ever be written as 0; the objection was
 * that the recorder had no timing at all, not that timing was unwanted. This adds the honest half:
 * the offset the recorder can actually observe. The Administration derives gaps and shows them as
 * gaps, claiming no durations.
 *
 * Offsets are relative to **construction**, and this object is built fresh per turn
 * ({@see \Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory},
 * {@see \Swag\AssistantStarterKit\Controller\AssistantController}), so they are turn-relative. A
 * conversation spans turns and `seq` keeps growing across them, so conversation-relative offsets
 * would be meaningless by turn three.
 */
final class TraceRecorder
{
    /** @var list<TraceEvent> */
    private array $events = [];

    private int $seq = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private readonly int $startedAt;

    /**
     * @param (\Closure(): int)|null $clock monotonic **nanosecond** source. Injected so timing is
     *                                      assertable: `hrtime(true)` is not steerable from a test,
     *                                      and an unasserted timing column is precisely what R62
     *                                      warned about.
     */
    public function __construct(?\Closure $clock = null)
    {
        // `hrtime(true)` is typed `int|float|false`. It returns `false` only on failure, in
        // which case every offset reads 0 — which the Administration renders as "—", the same
        // as a row written before the column existed. Degraded, never a wrong number.
        $this->clock = $clock ?? static fn(): int => (int) hrtime(true);
        $this->startedAt = ($this->clock)();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $stage, array $payload): void
    {
        $this->events[] = new TraceEvent($this->seq, $stage, $payload, $this->elapsedMs());
        $this->seq++;
    }

    private function elapsedMs(): int
    {
        $elapsedNs = ($this->clock)() - $this->startedAt;

        return (int) ($elapsedNs / 1_000_000);
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
