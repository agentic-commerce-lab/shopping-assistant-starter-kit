<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Whether a run found something to check out.
 *
 * Its own class for the reason {@see ShopInfoAnswer} is: {@see TurnOutcomeResolver} is a chain of
 * decisions, this is a question about the trace, and Mago bounds complexity per class — adding a
 * fourth scan inline pushed that class over its limit.
 */
final readonly class CheckoutOffer
{
    /**
     * The payload is read, not merely the stage's presence.
     *
     * `go_to_checkout` runs on an empty cart too, and that turn offered nothing: the outcome this
     * feeds is what renders the link, so a cart with nothing in it must not produce one. A checkout
     * link beside "your cart is empty" is the same empty promise
     * {@see \Swag\AssistantStarterKit\Controller\HandoffPayload} exists to stop making.
     *
     * Any event, not the last: one recorder spans every turn of a conversation (ruling R84), which is
     * the same reason {@see ShopInfoAnswer::isIn()} scans them all.
     */
    public static function isIn(TraceRecorder $trace): bool
    {
        foreach ($trace->events() as $event) {
            if ($event->stage !== GoToCheckoutTool::TRACE_STAGE) {
                continue;
            }

            if (($event->payload['empty'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }
}
