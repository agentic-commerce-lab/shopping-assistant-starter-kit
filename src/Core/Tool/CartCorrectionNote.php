<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

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

    /**
     * What a bundle add reports, instead of a corrected quantity.
     *
     * **A bundle does not occupy a line of its own.** Dumped from the live cart on 2026-09-10, one
     * added Roadside Repair Kit is four top-level `product` lines — its members, one of them at
     * quantity two — each carrying a `discount` child labelled with the bundle's name, and a cart
     * total equal to the bundle price. No line references the bundle's own product id.
     *
     * So {@see self::lineQuantity()} finds nothing and {@see self::text()} concluded "Nothing was
     * added", which the model relayed as *"already in your cart, and the quantity has not changed"*
     * — on a fresh session, over a cart it had just filled. Both halves false, from one absent line.
     *
     * There is no quantity to correct here: Shopware did not adjust anything, it expanded one line
     * into several. This says what happened and what the shopper will see on the cart page, so the
     * difference between the confirmation and the cart is explained rather than discovered.
     */
    public static function bundleText(string $name): string
    {
        return sprintf(
            'Added the %s to the cart. A bundle goes in as its individual items, so the cart lists '
            . 'those separately under the bundle\'s name rather than as one row, and the cart '
            . 'total is the bundle price.',
            $name,
        );
    }

    /**
     * The note for whatever was added, bundle or not.
     *
     * The branch lives here rather than at the call site because this class already owns every
     * wording decision, and because {@see \Swag\AssistantStarterKit\Core\Tool\AddToCartTool} sits
     * on the complexity gate — the standing constraints answer that with a split, not a suppression.
     */
    public static function noteFor(ProductCard $card, int $stored, int $requested, ?CartNoticeReason $reason): string
    {
        return $card->bundleItems === [] ? self::text($stored, $requested, $reason) : self::bundleText($card->name);
    }

    /**
     * The quantity fields the `cart.add` trace stage carries.
     *
     * A bundle has no line of its own to read a stored quantity off, and reporting the requested
     * figure as though Shopware had confirmed it would be the guess that stage exists to avoid. The
     * flag says which case a merchant is looking at; `storedQuantity` keeps its meaning for
     * everything else, so existing trace readers do not shift underneath them.
     *
     * @return array{storedQuantity: int}|array{bundleExpanded: true}
     */
    public static function traceFields(ProductCard $card, int $stored): array
    {
        return $card->bundleItems === [] ? ['storedQuantity' => $stored] : ['bundleExpanded' => true];
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
