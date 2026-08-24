<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Export;

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
 * The key order is the CSV's column order — {@see TraceCsvSerialiser} writes `array_values()` — so
 * reordering these keys moves every column under the wrong heading. `TraceExportRowTest` pins it.
 */
final class TraceExportRow
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
     *               outcome: string, totalMs: int, shopMs: int, modelMs: int, toolCalls: int}
     */
    public static function of(ConversationEntity $conversation, string $salesChannelName): array
    {
        $events = array_values($conversation->getEvents()?->getElements() ?? []);
        usort(
            $events,
            static fn(TraceEventEntity $a, TraceEventEntity $b): int => $a->getElapsedMs() <=> $b->getElapsedMs(),
        );

        $modelMs = 0;
        $toolCalls = 0;
        $previous = 0;

        foreach ($events as $event) {
            if (($event->getElapsedMs() - $previous) >= self::WAIT_THRESHOLD_MS) {
                $modelMs += $event->getElapsedMs() - $previous;
            }

            $previous = $event->getElapsedMs();

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
            'totalMs' => $totalMs,
            // Floored at zero: `total_ms` and the event offsets are written by different code
            // paths, and a negative "shop time" would be an arithmetic artefact reported as a fact.
            'shopMs' => max(0, $totalMs - $modelMs),
            'modelMs' => $modelMs,
            'toolCalls' => $toolCalls,
        ];
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
