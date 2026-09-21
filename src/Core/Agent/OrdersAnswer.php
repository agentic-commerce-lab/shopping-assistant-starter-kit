<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Whether a run actually put one of the shopper's own orders in front of them.
 *
 * Its own class for {@see ShopInfoAnswer}'s reasons: {@see TurnOutcomeResolver} is a chain of
 * decisions, this is a question about the trace, and mago bounds complexity per class.
 *
 * **Any turn of the run, not the last.** One recorder spans every turn of a conversation (ruling
 * R84), so reading only the final event would record a two-turn conversation by whichever half
 * happened to end it.
 *
 * **Found something, not merely looked.** A search that returned no orders is the feature working —
 * the shopper was told honestly that there are none — and recording that as an answer would make the
 * trace useless for the question a merchant most wants from it: how often does somebody ask about
 * orders and leave with nothing. Same distinction, and the same reasoning, as `accepted` in
 * {@see ShopInfoAnswer}.
 */
final readonly class OrdersAnswer
{
    public static function isIn(TraceRecorder $trace): bool
    {
        foreach ($trace->events() as $event) {
            if ($event->stage === 'orders.listed' && ($event->payload['orderNumbers'] ?? []) !== []) {
                return true;
            }

            if ($event->stage === 'orders.detail' && ($event->payload['found'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
