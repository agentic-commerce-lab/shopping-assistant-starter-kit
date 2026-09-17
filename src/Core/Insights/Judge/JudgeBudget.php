<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Keeps the request inside a character budget by dropping whole conversations.
 *
 * ## Why not truncate
 *
 * **Because silent truncation would keep the quote check passing while making it meaningless.**
 * {@see JudgeFindingRow} discards a finding whose quote does not occur in the conversation it
 * claims, and it is handed the complete trace regardless of what the request carried. Truncate a
 * transcript on the way out and the judge is reporting on words it never read, while the one guard
 * against invented evidence goes on confirming them against the full text. The guard's premise is
 * "everything the judge saw, it saw in full", and only dropping whole conversations keeps that true.
 *
 * An overflowing request fails loudly at the provider. A truncated one fails quietly and looks like
 * a finding. This class exists to make the first kind impossible without creating the second.
 *
 * ## The number
 *
 * {@see self::DEFAULT_MAX_CHARS} is four characters per token against a 100 000-token floor, which
 * every model worth configuring as a judge exceeds — the point of pointing this at a
 * large-context model is that it reads whole conversations at once. It is a bound, not a target:
 * a night that fits sends everything, and `dropped` records what a night that does not lost.
 */
final class JudgeBudget
{
    public const DEFAULT_MAX_CHARS = 400_000;

    private function __construct() {}

    /** @param list<ConversationTrace> $traces */
    public static function fit(array $traces, int $maxChars = self::DEFAULT_MAX_CHARS): FittedSample
    {
        $kept = [];
        $dropped = 0;
        $used = 0;

        foreach ($traces as $trace) {
            $cost = self::cost($trace);

            if (($used + $cost) > $maxChars) {
                ++$dropped;

                continue;
            }

            $kept[] = $trace;
            $used += $cost;
        }

        return new FittedSample($kept, $dropped);
    }

    /**
     * What one conversation costs the request, measured on the text that actually goes into it.
     *
     * The transcript and the stage names, because those are what {@see JudgeRequest::build()}
     * writes. Payloads are not counted: the request carries tool NAMES, not tool results, which is
     * the single biggest reason a night fits at all.
     */
    private static function cost(ConversationTrace $trace): int
    {
        $prose = 0;

        foreach ($trace->transcript as $turn) {
            $prose += mb_strlen($turn['prose']) + mb_strlen($turn['role']) + 2;
        }

        return $prose + (\count($trace->events) * 24);
    }
}
