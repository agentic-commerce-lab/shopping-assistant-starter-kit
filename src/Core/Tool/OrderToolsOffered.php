<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

/**
 * Whether a turn's constructed tools include one that reads the shopper's own orders.
 *
 * The one answer to "are order questions answerable this turn?", read off what was BUILT rather than
 * off the merchant's switch. `ListOrdersToolFactory` has three gates — the switch, a signed-in
 * shopper, a gateway that can read orders — and the construction is the only place all three have
 * already been applied. Re-deriving them anywhere else is how the escalate description and the prompt
 * would come to disagree with the toolbox about a guest.
 */
final class OrderToolsOffered
{
    private function __construct() {}

    /** @param list<object> $tools */
    public static function among(array $tools): bool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ListOrdersTool || $tool instanceof GetOrderTool) {
                return true;
            }
        }

        return false;
    }
}
