<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

/**
 * One conversation as the flat row a merchant counts with.
 *
 * **The shop/model split is derived here and only here.** The Administration derives the same two
 * numbers in `phases.js`, and the rule is identical: nothing is recorded while the model is
 * thinking, so the model's time is the *gaps between* events and the shop's is everything else. A
 * second derivation elsewhere is how a number in a file comes to disagree with the number on the
 * screen, which is the failure this class exists to prevent.
 *
 * It rides along in every exported trace so a reader gets the conversation's shape — who, how long,
 * how many tool calls — without deriving it from the events themselves.
 */
final class TraceExportSummary
{
    /**
     * Below this a gap is scheduling noise rather than a round trip — the same threshold
     * `phases.js` uses, and for the same measured reason: the largest within-phase gap on a real
     * turn was 17ms.
     */
    private const WAIT_THRESHOLD_MS = 250;

    private function __construct() {}

    /**
     * @return array{id: string, createdAt: string, salesChannel: string, user: string, turns: int,
     *               outcome: string, models: list<string>, totalMs: int, shopMs: int, modelMs: int,
     *               toolCalls: int}
     */
    public static function of(ConversationEntity $conversation, string $salesChannelName): array
    {
        $events = array_values($conversation->getEvents()?->getElements() ?? []);

        // **Sorted by `seq`, not by `elapsedMs`, and that was the bug.** `elapsedMs` is measured from
        // the start of ITS OWN TURN and restarts at zero on the next one, so ordering a whole
        // conversation by it interleaves the turns: turn two's fifth millisecond sorted ahead of turn
        // one's third second. The walk below then measured gaps between events from different turns
        // and called the result model time.
        //
        // Measured on the September 2026 export: single-reply conversations reconciled exactly, and
        // an eleven-reply one reported 11,554 ms of model time against roughly 65,800 ms of actual
        // waiting. Every multi-turn row in that export was wrong, and the shape of the error — right
        // for one turn, wrong for many — is why it survived.
        //
        // `seq` is written in event order across the whole conversation and is monotonic, so it is
        // the only field that orders these correctly.
        usort($events, static fn(TraceEventEntity $a, TraceEventEntity $b): int => $a->getSeq() <=> $b->getSeq());

        $modelMs = 0;
        $toolCalls = 0;
        $previous = 0;

        foreach ($events as $event) {
            $elapsed = $event->getElapsedMs();

            // `max(0, ...)` is the turn boundary, and it is arithmetic rather than a branch on
            // purpose. A boundary shows as the clock going backwards; there is no gap across it, and
            // the first event of a turn sits within a millisecond or two of its own zero anyway. It
            // is read from the value rather than from `turn.end`, because a turn that failed inside
            // the agent never wrote one, and a walk that trusted the marker would merge two turns.
            $gap = max(0, $elapsed - $previous);

            if ($gap >= self::WAIT_THRESHOLD_MS) {
                $modelMs += $gap;
            }

            $previous = $elapsed;

            if ($event->getStage() === 'tool.call') {
                ++$toolCalls;
            }
        }

        $totalMs = $conversation->getTotalMs();

        return [
            'id' => $conversation->getId(),
            'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            'salesChannel' => $salesChannelName,
            'user' => self::user($conversation),
            'turns' => $conversation->getTurnCount(),
            'outcome' => (string) $conversation->getOutcome(),
            'models' => self::models($events),
            'totalMs' => $totalMs,
            // Floored at zero: `total_ms` and the event offsets are written by different code
            // paths, and a negative "shop time" would be an arithmetic artefact reported as a fact.
            'shopMs' => max(0, $totalMs - $modelMs),
            'modelMs' => $modelMs,
            'toolCalls' => $toolCalls,
        ];
    }

    /**
     * Every model that answered a turn of this conversation, distinct and in the order first seen.
     *
     * **A list, not a string.** The model is a per-sales-channel setting, so a conversation whose
     * turns straddle a config change genuinely used two of them, and a scalar here would have to
     * pick one and be wrong about the other turn. Almost always a list of one; a list of two is the
     * answer to "why did this conversation start well and end badly".
     *
     * Empty for a conversation recorded before {@see AssistantAgentFactory::MODEL_STAGE} existed.
     * That is the honest answer — nothing in the row can name a model a turn never wrote down — and
     * it is the same treatment an offset of `0` gets from a row written before `elapsed_ms` did.
     *
     * @param list<TraceEventEntity> $events
     *
     * @return list<string>
     */
    private static function models(array $events): array
    {
        $recorded = array_filter(
            $events,
            static fn(TraceEventEntity $event): bool => $event->getStage() === AssistantAgentFactory::MODEL_STAGE,
        );

        $names = array_map(static fn(TraceEventEntity $event): string => self::modelName(
            $event->getPayload()['name'] ?? null,
        ), $recorded);

        // `array_filter` with no callback drops the empty strings a malformed payload produced, and
        // `array_unique` keeps the first occurrence of each name — which is what makes this the
        // order first seen rather than the order last seen.
        return array_values(array_unique(array_filter($names)));
    }

    /**
     * A payload's `name` as a string, or `''` for anything that is not one.
     *
     * The payload is a JSON column: what comes back is whatever was written, and a row written by
     * an older version — or by hand — can hold anything at all. An empty string is the one value
     * {@see self::models()} drops, so a malformed name is absent from the summary rather than
     * printed as `null` or coerced into `"Array"`.
     */
    private static function modelName(mixed $name): string
    {
        return \is_string($name) ? $name : '';
    }

    /**
     * Two states, and the second is not a fallback. `ON DELETE SET NULL` removes the id when a
     * customer deletes their account, so a conversation with no customer genuinely has none —
     * whether it never had one or no longer does.
     */
    private static function user(ConversationEntity $conversation): string
    {
        $customer = $conversation->getCustomer();

        if ($customer === null) {
            return 'Guest user';
        }

        return trim($customer->getFirstName() . ' ' . $customer->getLastName());
    }
}
