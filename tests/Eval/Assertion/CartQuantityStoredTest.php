<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\CartQuantityStored;
use Swag\AssistantStarterKit\Tests\Support\BuildsEvalCards;

/**
 * Reads the ALLOWED `cart.add` event's `storedQuantity` — never the reply's prose. This is
 * the assertion `cart_quantity_corrected` needs to actually catch a misreported quantity:
 * `cart_contains` alone would still pass a reply claiming "added 10" over a cart holding 8.
 */
final class CartQuantityStoredTest extends TestCase
{
    use BuildsEvalCards;

    public function testFailsWhenNoAllowedAddWasRecordedForTheVariant(): void
    {
        $trace = new TraceRecorder();

        $result = (new CartQuantityStored())->evaluate($this->turnWithCard('fx-021'), $trace, [
            'variantId' => 'fx-021',
            'quantity' => 8,
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('no allowed add_to_cart', $result->detail);
    }

    public function testFailsWhenTheStoredQuantityDiffersFromExpected(): void
    {
        // The exact defect this assertion exists to catch: the tool asked to add 10 of a
        // product sold in fours, and the trace honestly recorded that only 8 were stored.
        $trace = new TraceRecorder();
        $trace->record(AddToCartTool::TRACE_STAGE, [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => 'fx-021',
            'quantity' => 10,
            'storedQuantity' => 8,
        ]);

        $result = (new CartQuantityStored())->evaluate($this->turnWithCard('fx-021'), $trace, [
            'variantId' => 'fx-021',
            'quantity' => 10,
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('was 8, expected 10', $result->detail);
    }

    public function testPassesWhenTheStoredQuantityMatchesExpected(): void
    {
        $trace = new TraceRecorder();
        $trace->record(AddToCartTool::TRACE_STAGE, [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => 'fx-021',
            'quantity' => 10,
            'storedQuantity' => 8,
        ]);

        $result = (new CartQuantityStored())->evaluate($this->turnWithCard('fx-021'), $trace, [
            'variantId' => 'fx-021',
            'quantity' => 8,
        ]);

        self::assertTrue($result->passed);
    }

    public function testDoesNotMistakeABlockedAddForAnAllowedOne(): void
    {
        // A blocked attempt carries no `storedQuantity` at all — must read as "no allowed
        // add recorded", the same as no event, not as a quantity mismatch against null.
        $trace = new TraceRecorder();
        $trace->record(AddToCartTool::TRACE_STAGE, [
            'name' => 'add_to_cart',
            'policyVerdict' => 'block',
            'policyReasonCode' => 'cart_limit',
        ]);

        $result = (new CartQuantityStored())->evaluate($this->turnWithCard('fx-021'), $trace, [
            'variantId' => 'fx-021',
            'quantity' => 8,
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('no allowed add_to_cart', $result->detail);
    }

    public function testIsQualityNotSafety(): void
    {
        self::assertFalse((new CartQuantityStored())->isSafety());
        self::assertSame('cart_quantity_stored', (new CartQuantityStored())->name());
    }
}
