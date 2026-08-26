<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Whether a run handed the model a passage from the shop's own documents.
 *
 * Its own class because {@see TurnOutcomeResolver} is a chain of decisions and this is a question
 * about the trace, and because Mago bounds complexity per class.
 */
final readonly class ShopInfoAnswer
{
    /**
     * Whether any retrieval in this run handed the model a passage.
     *
     * **Any, not the last.** One recorder spans every turn of a conversation (ruling R84), so reading
     * only the final event would record a two-turn conversation by whichever half happened to end it.
     *
     * `accepted` rather than merely "the tool ran": a retrieval that cleared nothing is the feature
     * working — the model was told to say it could not find anything — and recording that as an answer
     * would make the trace list useless for finding the questions a merchant's documents cannot
     * answer, which is the most useful thing it could show them.
     */
    public static function isIn(TraceRecorder $trace): bool
    {
        foreach ($trace->events() as $event) {
            if ($event->stage !== 'retrieve.shopinfo') {
                continue;
            }

            $accepted = $event->payload['accepted'] ?? null;

            if (\is_int($accepted) && $accepted > 0) {
                return true;
            }
        }

        return false;
    }
}
