<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Turns that did not finish, and escalations with nowhere to go.
 *
 * Both are the merchant's to fix and neither is visible to them today. Measured live on 2026-09-16,
 * two of four multi-category missions ended in `tool_limit_exceeded` because
 * `maxToolCallsPerTurn` was configured to 5 — a number in the administration, not a defect in the
 * plugin. The shopper was told to "ask about one product at a time". This metric is what would have
 * surfaced that without anyone running a mission by hand.
 *
 * An escalation with an empty destination is the same shape of problem: the assistant did the right
 * thing and the configuration dropped it on the floor.
 */
final readonly class TurnHealth
{
    public const ABORTED_OUTCOMES = ['tool_limit_exceeded', 'failed'];

    private function __construct(
        public int $abortedTurns,
        public int $escalations,
        public int $escalationsWithoutDestination,
    ) {}

    /** @param list<ConversationTrace> $traces */
    public static function of(array $traces): self
    {
        $aborted = 0;
        $escalations = 0;
        $withoutDestination = 0;

        foreach ($traces as $trace) {
            foreach ($trace->events as $event) {
                if (
                    $event['stage'] === 'turn.end'
                    && \in_array($event['payload']['outcome'] ?? '', self::ABORTED_OUTCOMES, true)
                ) {
                    ++$aborted;
                }

                if ($event['stage'] !== 'escalate') {
                    continue;
                }

                ++$escalations;

                // `hasDestination`, the bool EscalateTool writes — NOT a `destination` string,
                // which nothing has ever recorded. Read the wrong key and the `?? ''` fallback
                // matches on every escalation, so the count equals `escalations` for every shop and
                // the administration shows a configuration error that is not there. That is what it
                // did until 2026-09-18, measured on a shop whose escalation URL was set and whose
                // four events all carried `hasDestination: true`.
                //
                // `!== true` rather than `=== false`: an event from before the field existed has
                // neither, and "we did not record a destination" is not evidence that one was
                // configured.
                if (($event['payload']['hasDestination'] ?? false) !== true) {
                    ++$withoutDestination;
                }
            }
        }

        return new self($aborted, $escalations, $withoutDestination);
    }
}
