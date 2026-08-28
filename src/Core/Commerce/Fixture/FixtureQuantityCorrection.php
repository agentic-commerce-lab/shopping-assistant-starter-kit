<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;

/**
 * Split out of {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway::addToCart()}
 * to keep that class's own cyclomatic-complexity total under this project's threshold (mago sums
 * it per class, across every method) — the same reason {@see \Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote}
 * was split out of `AddToCartTool`. Nothing here changes the arithmetic, only which class owns it.
 *
 * Mirrors `ProductCartProcessor::validateStock()`'s two phases exactly, in that order:
 *
 * 1. A quantity **below** the minimum is raised straight to it and stops there.
 * 2. Only a quantity that already clears the minimum is step-rounded.
 *
 * Collapsing both into one step-rounding formula is the mistake this split exists to prevent:
 * `min + floor((quantity - min) / steps) * steps` returns a **negative-floor** result for a
 * below-minimum quantity — min 4, steps 4, asked 1 rounds to 0, not 4 — because the phases apply
 * to disjoint ranges of `$quantity` and the formula was only ever valid for the second one.
 */
final class FixtureQuantityCorrection
{
    /** What Shopware would actually store for this ask. */
    public static function corrected(int $min, int $quantity, int $steps): int
    {
        if ($quantity < $min) {
            return $min;
        }

        return (int) ($min + (floor(($quantity - $min) / $steps) * $steps));
    }

    /**
     * Which of the two phases explains a correction. Only meaningful when `corrected()` returned
     * something other than `$quantity` — a caller that already knows the two agree has no reason
     * to ask why they don't.
     */
    public static function reasonFor(int $min, int $quantity): CartNoticeReason
    {
        return $quantity < $min ? CartNoticeReason::MinimumQuantity : CartNoticeReason::PurchaseSteps;
    }
}
