<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Swag\AssistantStarterKit\Core\Trace\TranscriptCodec;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * Reads a window's conversations out of the database and maps them to {@see ConversationTrace}.
 *
 * A thin adapter with one decision in it: **events are sorted by `seq`**, because `elapsed_ms`
 * restarts every turn and time therefore does not order a conversation. The trace export sorts the
 * same way and for the same reason; a set ordered by anything else describes a turn the pipeline
 * did not run, and would hand the judge a conversation in which the answer precedes the question.
 *
 * Selected by `createdAt` within the half-open window, so a conversation that spans midnight is
 * counted in the window its first turn falls in and read in full. A turn is never split from its
 * conversation: a judge reading half a conversation reports a missing answer that was given.
 *
 * The transcript is decoded by {@see TranscriptCodec}, not by a second reader written here. There
 * is one stored shape and it should have one decoder — and that one already refuses a malformed
 * entry rather than yielding an empty turn, because an empty turn reads as "the assistant said
 * nothing", which is a lie about the conversation. A hand-rolled decoder in this class also took it
 * over the cyclomatic-complexity threshold, which is how the duplication announced itself.
 */
final readonly class ConversationTraceLoader implements ConversationTraceSource
{
    /** @param EntityRepository<\Swag\AssistantStarterKit\Entity\Conversation\ConversationCollection> $conversations */
    public function __construct(
        private EntityRepository $conversations,
        private TranscriptCodec $transcripts,
    ) {}

    /** @return list<ConversationTrace> */
    public function inWindow(InsightWindow $window): array
    {
        if ($window->isEmpty()) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $window->start->format(\DATE_ATOM),
            RangeFilter::LT => $window->end->format(\DATE_ATOM),
        ]));
        $criteria->addAssociation('events');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

        $traces = [];

        foreach ($this->conversations
            ->search($criteria, Context::createDefaultContext())
            ->getElements() as $conversation) {
            if (!$conversation instanceof ConversationEntity) {
                continue;
            }

            $traces[] = self::traceOf($conversation);
        }

        return $traces;
    }

    private function traceOf(ConversationEntity $conversation): ConversationTrace
    {
        $events = array_values($conversation->getEvents()?->getElements() ?? []);
        usort($events, static fn(TraceEventEntity $a, TraceEventEntity $b): int => $a->getSeq() <=> $b->getSeq());

        $transcript = [];

        foreach ($this->transcripts->decodeAll($conversation->getTranscript() ?? []) as $turn) {
            $transcript[] = ['role' => $turn->role, 'prose' => $turn->prose];
        }

        return new ConversationTrace(
            id: $conversation->getId(),
            createdAt: \DateTimeImmutable::createFromInterface(
                $conversation->getCreatedAt() ?? new \DateTimeImmutable(),
            ),
            events: array_map(static fn(TraceEventEntity $event): array => [
                'seq' => $event->getSeq(),
                'stage' => $event->getStage(),
                'payload' => $event->getPayload() ?? [],
            ], $events),
            transcript: $transcript,
        );
    }
}
