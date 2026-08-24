<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * Conversations in full, for handing to whoever has to work out what happened.
 *
 * Every event payload is carried **unchanged**. A summarised export answers the questions its author
 * thought of, and the reason anyone asks for a trace is a question nobody thought of.
 *
 * The same `summary` block the CSV rows are built from rides along, so the two formats cannot
 * disagree about a duration — see {@see TraceExportRow}.
 */
final class TraceJsonSerialiser
{
    private function __construct() {}

    /**
     * @param list<ConversationEntity> $conversations
     * @param array<string, string>    $salesChannelNames id => name
     */
    public static function serialise(array $conversations, array $salesChannelNames): string
    {
        $out = [];

        foreach ($conversations as $conversation) {
            $channelId = $conversation->getSalesChannelId();

            $out[] = [
                'summary' => TraceExportRow::of($conversation, $salesChannelNames[$channelId] ?? $channelId),
                'transcript' => $conversation->getTranscript() ?? [],
                'events' => self::events($conversation),
            ];
        }

        return json_encode(
            $out,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Sorted by `seq`, which is the record of what happened when. A set ordered by anything else
     * describes a turn the pipeline did not run.
     *
     * @return list<array{seq: int, stage: string, elapsedMs: int, payload: array<string, mixed>}>
     */
    private static function events(ConversationEntity $conversation): array
    {
        $events = array_values($conversation->getEvents()?->getElements() ?? []);
        usort($events, static fn(TraceEventEntity $a, TraceEventEntity $b): int => $a->getSeq() <=> $b->getSeq());

        return array_map(static fn(TraceEventEntity $event): array => [
            'seq' => $event->getSeq(),
            'stage' => $event->getStage(),
            'elapsedMs' => $event->getElapsedMs(),
            'payload' => $event->getPayload() ?? [],
        ], $events);
    }
}
