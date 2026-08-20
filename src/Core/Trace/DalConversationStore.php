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

        // events(), never stages(): stages() de-duplicates by design (ruling R18), so a store built
        // on it would drop the second tool round — which is where the tool-call budget failures live,
        // and exhausting that budget was a live pilot blocker (ruling R52).
        foreach ($trace->events() as $event) {
            $events[] = [
                'id' => Uuid::randomHex(),
                'conversationId' => $token,
                'seq' => $event->seq,
                'stage' => $event->stage,
                'payload' => $event->payload,
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
