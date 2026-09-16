<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

/**
 * One conversation as the aggregator and the judge read it: its events in `seq` order and its
 * transcript.
 *
 * **Sorted by `seq` and nothing else.** `elapsed_ms` restarts every turn, so time does not order a
 * conversation — the trace export sorts the same way and for the same reason. A set ordered by
 * anything else describes a turn the pipeline did not run.
 *
 * A plain value object rather than the DAL entity, because the aggregator must be runnable over a
 * JSON export months later. A metric that needed the database to compute could not be checked.
 */
final readonly class ConversationTrace
{
    /**
     * @param list<array{seq:int,stage:string,payload:array<string,mixed>}> $events     in `seq` order
     * @param list<array{role:string,prose:string}>                         $transcript
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public array $events,
        public array $transcript,
    ) {}

    /** @return list<array{seq:int,stage:string,payload:array<string,mixed>}> */
    public function eventsOfStage(string $stage): array
    {
        return array_values(array_filter($this->events, static fn(array $e): bool => $e['stage'] === $stage));
    }

    /** @return list<string> the assistant's own replies, in order */
    public function assistantProse(): array
    {
        return array_values(array_map(
            static fn(array $t): string => $t['prose'],
            array_filter($this->transcript, static fn(array $t): bool => $t['role'] === 'assistant'),
        ));
    }
}
