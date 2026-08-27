<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Reads the machine-readable outcome for one turn straight out of the trace,
 * per the task brief's priority order: an `escalate` event beats a
 * successful cart add, which beats cards having been shown, which beats the
 * fallback of nothing having happened at all.
 *
 * Split out of {@see AssistantRunner} to keep that class's own job — driving
 * the guard, the agent call and the message bag — from growing past a single
 * responsibility; mago's cyclomatic-complexity check flagged the combined
 * class. Tool-call counting is a separate, unrelated concern, split further
 * into {@see TurnToolCallCounter} for the same reason.
 *
 * This is also where the full outcome vocabulary lives, even the values
 * {@see self::outcome()} itself never returns. `error` (the pre-flight guard
 * blocked before any spend) is decided in {@see AssistantRunner} before this
 * class is ever consulted, and {@see self::TOOL_LIMIT_EXCEEDED} likewise: it
 * is set by `AssistantRunner` directly when {@see \Swag\AssistantStarterKit\Core\Agent\BoundedToolbox}'s
 * tool-call cap cuts a turn short before the model ever produced a final
 * reply, because at that point there is no trace shape for `self::outcome()`
 * to read a *normal* end from — the turn didn't have one.
 */
final class TurnOutcomeResolver
{
    /**
     * A turn cut short by the tool-call cap: real cards may still have been
     * retrieved and are returned on the {@see \Swag\AssistantStarterKit\Core\Agent\AssistantTurn},
     * but the model never produced a final reply. Distinct from `error` (the
     * guard blocked before the platform was ever touched) so a trace can tell
     * "never tried" apart from "tried and ran out of budget".
     */
    public const TOOL_LIMIT_EXCEEDED = 'tool_limit_exceeded';

    /**
     * The turn was handed a passage from one of the shop's own documents.
     *
     * **Added because `no_result` had quietly become false.** That value meant "no product cards were
     * rendered", which was the same thing as "nothing happened" right up until shop-information
     * retrieval existed. Measured on the lab shop afterwards: a correct revocation period, a correct
     * imprint and a correct shipping cost were all recorded as `no_result`, and a merchant reading the
     * trace list would have seen three failures where there were three good answers.
     *
     * **`retrieved`, not `answered`, and the distinction is the whole point.** Under spec R3a passages
     * above the recall floor reach the model even when none of them answers the question — that is the
     * design, and the model is expected to decline. Measured: "what warranty period applies to frame
     * breakage?" retrieves withdrawal passages and is correctly declined. Whether the model *used* a
     * passage is not something the server can know, and the only way to guess would be to read the
     * prose — which is the one thing this pipeline refuses to trust. So the name claims exactly what
     * the trace proves: passages were retrieved and given to the model.
     *
     * It ranks below `product_shown` on purpose. Cards are grounded facts the server rendered; this is
     * text the server retrieved and the model may or may not have used. When a turn produced both, the
     * stronger claim is the one worth recording.
     */
    public const SHOP_INFO_RETRIEVED = 'shop_info_retrieved';

    /**
     * @param list<ProductCard> $cards
     */
    public function outcome(TraceRecorder $trace, array $cards): string
    {
        if (\in_array('escalate', $trace->stages(), strict: true)) {
            return 'escalated';
        }

        if ($this->hasAllowedCartAdd($trace)) {
            return 'cart_added';
        }

        if ($cards !== []) {
            return 'product_shown';
        }

        if (ShopInfoAnswer::isIn($trace)) {
            return self::SHOP_INFO_RETRIEVED;
        }

        return 'no_result';
    }

    private function hasAllowedCartAdd(TraceRecorder $trace): bool
    {
        foreach ($trace->events() as $event) {
            if (AddToCartTool::TRACE_STAGE !== $event->stage) {
                continue;
            }

            if (
                'add_to_cart' === ($event->payload['name'] ?? null)
                && 'allowed' === ($event->payload['policyReasonCode'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }
}
