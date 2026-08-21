<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * {@see ConversationStore} over `swag_assistant_conversation` and `swag_assistant_trace_event`.
 *
 * Writes run in {@see Context::createDefaultContext()} rather than the shopper's context: the
 * assistant records its own trace, it is not acting on a customer's behalf, and a shopper has no
 * write authority over these tables at all.
 *
 * The conversation row is created once by {@see self::start()} and then updated in place, so the
 * transcript grows server-side. The widget's copy is a cache, never the source of truth — treating it
 * as authoritative would let a stale browser tab overwrite newer turns.
 */
final readonly class DalConversationStore implements ConversationStore
{
    public function __construct(
        private EntityRepository $conversationRepository,
        private EntityRepository $eventRepository,
        private TranscriptCodec $codec = new TranscriptCodec(),
        private JsonShape $shape = new JsonShape(),
    ) {}

    public function start(string $salesChannelId, string $locale): string
    {
        $id = Uuid::randomHex();

        $this->conversationRepository->create([[
            'id' => $id,
            'salesChannelId' => $salesChannelId,
            'locale' => $locale,
            'turnCount' => 0,
            'outcome' => '',
            'totalMs' => 0,
            'transcript' => [],
        ]], Context::createDefaultContext());

        return $id;
    }

    public function append(#[\SensitiveParameter] string $token, ConversationTurn $turn, TraceRecorder $trace): void
    {
        $context = Context::createDefaultContext();

        $transcript = $this->transcript($token, $context);
        $transcript[] = $this->codec->encode($turn);

        $this->conversationRepository->update(
            [[
                'id' => $token,
                'transcript' => $transcript,
                'turnCount' => \count($transcript),
                // The LAST turn's outcome: it answers "how did this conversation end", and one that
                // recovered after an error did not end in an error.
                'outcome' => $turn->outcome,
            ]],
            $context,
        );

        $events = [];

        // `TraceEvent::$seq` restarts at 0 for every turn, because `TraceRecorder` is built fresh per
        // turn — but this table is ordered by `seq` **within a conversation**. Storing the raw value
        // makes a multi-turn trace unreadable: measured in the real shop, one conversation had two
        // different events both at seq 13, from different turns, and `traceEvents()` interleaved them.
        //
        // Offsetting by what is already stored makes `seq` monotonic per conversation, which is what
        // the ordering assumes. Turn boundaries stay visible without a second column: every turn ends
        // with a `turn.end` event.
        $offset = $this->nextSeq($token, $context);

        // events(), never stages(): stages() de-duplicates by design (ruling R18), so a store built
        // on it would drop the second tool round — which is where the tool-call budget failures live,
        // and exhausting that budget was a live pilot blocker (ruling R52).
        foreach ($trace->events() as $event) {
            $events[] = [
                'id' => Uuid::randomHex(),
                'conversationId' => $token,
                'seq' => $offset + $event->seq,
                'stage' => $event->stage,
                'payload' => $event->payload,
                'elapsedMs' => $event->elapsedMs,
            ];
        }

        if ($events !== []) {
            $this->eventRepository->create($events, $context);
        }
    }

    public function history(#[\SensitiveParameter] string $token, int $limit = 20): array
    {
        $turns = $this->codec->decodeAll($this->transcript($token, Context::createDefaultContext()));

        // The tail, not the head: the context window is bounded so history has to be, and
        // "add that to my cart" refers to the newest card set — so the OLDEST turns get dropped.
        return \array_slice($turns, -$limit);
    }

    public function traceEvents(#[\SensitiveParameter] string $token): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('conversationId', $token));
        $criteria->addSorting(new FieldSorting('seq'));

        $events = [];

        foreach ($this->eventRepository->search($criteria, Context::createDefaultContext()) as $entity) {
            if (!$entity instanceof TraceEventEntity) {
                continue;
            }

            $events[] = new TraceEvent(
                seq: $entity->getSeq(),
                stage: $entity->getStage(),
                payload: $this->shape->map($entity->getPayload()),
            );
        }

        return $events;
    }

    /**
     * One past the highest `seq` already stored for this conversation.
     *
     * Read rather than counted: a `COUNT(*)` would be wrong the moment an event was ever removed,
     * and the retention task exists precisely to remove them.
     */
    private function nextSeq(#[\SensitiveParameter] string $token, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('conversationId', $token));
        $criteria->addSorting(new FieldSorting('seq', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $last = $this->eventRepository->search($criteria, $context)->first();

        return $last instanceof TraceEventEntity ? $last->getSeq() + 1 : 0;
    }

    /**
     * The stored transcript, or an empty list.
     *
     * An unknown token yields `[]` rather than an error: a shopper with a stale `sessionStorage`
     * token must get a fresh conversation, not a 500 on page load — and a widget that breaks the
     * page it is embedded in is worse than no widget.
     *
     * @return array<int, mixed>
     */
    private function transcript(#[\SensitiveParameter] string $token, Context $context): array
    {
        $conversation = $this->conversationRepository->search(new Criteria([$token]), $context)->first();

        if (!$conversation instanceof ConversationEntity) {
            return [];
        }

        return array_values($conversation->getTranscript() ?? []);
    }
}
