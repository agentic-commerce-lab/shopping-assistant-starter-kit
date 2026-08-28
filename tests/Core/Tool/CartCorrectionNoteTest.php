<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNotice;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote;

/**
 * Covers `CartCorrectionNote::reasonFor()` in isolation from {@see \Swag\AssistantStarterKit\Core\Tool\AddToCartTool}.
 *
 * `Processor::runProcessors()` copies a cart's persistent errors into the next cart before its
 * processors run, and `CartService` caches the processed cart per token — so two tool calls in one
 * HTTP request see errors accumulate rather than reset. `reasonFor()` used to return the FIRST
 * matching notice, which is the stale, carried-over one; this pins the fix, which takes the LAST.
 */
final class CartCorrectionNoteTest extends TestCase
{
    private const VARIANT_ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    public function testTheLaterNoticeForTheSameVariantWinsOverAStaleOne(): void
    {
        // The shape of the real defect: a stale MinimumQuantity notice from an earlier call in the
        // same request, followed by the fresh PurchaseSteps notice this call actually produced.
        // The first-match version of reasonFor() would report the stale reason.
        $cart = new CartSummary(notices: [
            new CartNotice(self::VARIANT_ID, CartNoticeReason::MinimumQuantity),
            new CartNotice(self::VARIANT_ID, CartNoticeReason::PurchaseSteps),
        ]);

        self::assertSame(CartNoticeReason::PurchaseSteps, CartCorrectionNote::reasonFor($cart, self::VARIANT_ID));
    }

    public function testAnUnattributedNoticeIsNotClaimedForAnotherVariant(): void
    {
        $cart = new CartSummary(notices: [
            new CartNotice('', CartNoticeReason::StockLimited),
        ]);

        self::assertNull(CartCorrectionNote::reasonFor($cart, self::VARIANT_ID));
    }
}
