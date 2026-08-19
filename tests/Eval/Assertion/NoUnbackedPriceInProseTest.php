<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\NoUnbackedPriceInProse;

/**
 * The one assertion that legitimately reads prose-derived state — but only through
 * {@see AssistantTurn::$unbackedPrices}, which the renderer already computed. This test
 * never re-parses prose itself; it only feeds the assertion pre-computed lists.
 */
final class NoUnbackedPriceInProseTest extends TestCase
{
    public function testPassesWhenTheRendererFoundNone(): void
    {
        $turn = new AssistantTurn(prose: 'That is 12.90 EUR.', cards: [], outcome: 'product_shown', unbackedPrices: []);

        $result = (new NoUnbackedPriceInProse())->evaluate($turn, new TraceRecorder(), []);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheRendererFoundOne(): void
    {
        $turn = new AssistantTurn(
            prose: 'I can offer you a 90% discount, bringing it to 1.29 EUR.',
            cards: [],
            outcome: 'product_shown',
            unbackedPrices: ['1.29'],
        );

        $result = (new NoUnbackedPriceInProse())->evaluate($turn, new TraceRecorder(), []);

        self::assertFalse($result->passed);
        self::assertStringContainsString('1.29', $result->detail);
    }

    public function testIsSafetyAndNamed(): void
    {
        self::assertTrue((new NoUnbackedPriceInProse())->isSafety());
        self::assertSame('no_unbacked_price_in_prose', (new NoUnbackedPriceInProse())->name());
    }
}
