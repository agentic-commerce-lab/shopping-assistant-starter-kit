<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;

/**
 * Turns what Shopware's cart actually holds into the sentence {@see AddToCartTool} returns.
 *
 * Split out of `AddToCartTool` rather than left inline, for the same reason documented on
 * {@see Guard}: mago's `cyclomatic-complexity` rule is class-scoped and sums every method's own
 * complexity, and these four small, branch-heavy static methods pushed `AddToCartTool`'s own total
 * over the project's threshold. Nothing here changes `AddToCartTool`'s behaviour or the note text a
 * shopper reads — only which class owns the arithmetic and the wording.
 */
final class CartCorrectionNote
{
    public static function lineQuantity(CartSummary $cart, string $variantId): int
    {
        foreach ($cart->lineItems as $line) {
            if ($line->variantId === $variantId) {
                return $line->quantity;
            }
        }

        return 0;
    }

    /**
     * The LAST matching notice, not the first.
     *
     * `Processor::runProcessors()` copies a cart's persistent errors into the next cart *before*
     * its processors run, and `CartService` caches the processed cart per token — so within one
     * HTTP request handling two tool calls, errors accumulate rather than reset. A stale notice
     * from an earlier call therefore sorts before the fresh one this call actually produced, and
     * taking the first would explain the right quantity with the wrong, carried-over reason.
     * Fresh errors are appended after carried-over ones, so the last match is the current one.
     */
    public static function reasonFor(CartSummary $cart, string $variantId): ?CartNoticeReason
    {
        $reason = null;

        foreach ($cart->notices as $notice) {
            // An unattributed notice ('' variant id) is not claimed for this variant: a cart
            // carries every line's complaints, and explaining one product's result with another
            // product's problem is a new lie in place of the old one.
            if ($notice->variantId === $variantId) {
                $reason = $notice->reason;
            }
        }

        return $reason;
    }

    /**
     * English on purpose, like every other note in {@see AddToCartTool}: the model localises it
     * for the shopper.
     */
    public static function text(int $stored, int $requested, ?CartNoticeReason $reason): string
    {
        if ($stored === $requested) {
            return sprintf('Added %d to the cart.', $stored);
        }

        if ($stored === 0) {
            return sprintf('Nothing was added%s.', self::because($reason));
        }

        return sprintf('Added %d rather than %d%s.', $stored, $requested, self::because($reason));
    }

    private static function because(?CartNoticeReason $reason): string
    {
        return match ($reason) {
            CartNoticeReason::MinimumQuantity => ': this product has a minimum order quantity',
            CartNoticeReason::PurchaseSteps => ': this product is sold in fixed steps',
            CartNoticeReason::StockLimited => ': that is all the stock there is',
            CartNoticeReason::OutOfStock => ': the product is out of stock',
            // Including null. Something changed the quantity and this code does not know what;
            // saying so beats naming a reason it did not observe.
            default => '; the shop adjusted the quantity',
        };
    }
}
