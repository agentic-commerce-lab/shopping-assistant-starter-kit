<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\CartContains;
use Swag\AssistantStarterKit\Tests\Support\BuildsEvalCards;

/**
 * Reads `turn.end.outcome` and `add_to_cart` `tool.call` events — never the prose.
 */
final class CartContainsTest extends TestCase
{
    use BuildsEvalCards;

    public function testFailsWhenTheOutcomeIsNotCartAdded(): void
    {
        $trace = new TraceRecorder();
        $trace->record('turn.end', ['outcome' => 'product_shown', 'cards' => [], 'retrievalStagesAndToolCalls' => 1]);

        $result = (new CartContains())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'variantId' => 'fx-026-blue-l',
        ]);

        self::assertFalse($result->passed);
    }

    public function testFailsWhenTheAddedVariantDiffersFromExpected(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.call', [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => 'fx-026-blue-m',
            'quantity' => 1,
        ]);
        $trace->record('turn.end', ['outcome' => 'cart_added', 'cards' => [], 'retrievalStagesAndToolCalls' => 1]);

        $result = (new CartContains())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'variantId' => 'fx-026-blue-l',
        ]);

        self::assertFalse($result->passed);
    }

    public function testPassesWhenTheExpectedVariantWasAdded(): void
    {
        $trace = new TraceRecorder();
        $trace->record('tool.call', [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => 'fx-026-blue-l',
            'quantity' => 1,
        ]);
        $trace->record('turn.end', ['outcome' => 'cart_added', 'cards' => [], 'retrievalStagesAndToolCalls' => 1]);

        $result = (new CartContains())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'variantId' => 'fx-026-blue-l',
        ]);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheTurnEndStageNeverFired(): void
    {
        // Ruling R40: absence of `turn.end` must fail loudly as "the turn never
        // completed", not read as "outcome is null" and fall through to the ordinary
        // wrong-outcome message — the two are different failures.
        $trace = new TraceRecorder();

        $result = (new CartContains())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'variantId' => 'fx-026-blue-l',
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('required stage', $result->detail);
        self::assertStringContainsString('turn.end', $result->detail);
    }

    public function testIsQualityNotSafety(): void
    {
        self::assertFalse((new CartContains())->isSafety());
        self::assertSame('cart_contains', (new CartContains())->name());
    }
}
