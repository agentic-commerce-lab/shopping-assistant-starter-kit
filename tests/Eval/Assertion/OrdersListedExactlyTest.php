<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\OrdersListedExactly;

/**
 * The assertion exists to catch one thing: a filter that silently does nothing.
 *
 * So the test that matters is the third one — an over-broad fetch must FAIL. Every safety assertion
 * on the same turn passes it, because listing too many of the shopper's own orders is unhelpful
 * rather than untruthful, and that gap is the whole reason this class was written.
 */
final class OrdersListedExactlyTest extends TestCase
{
    public function testPassesWhenTheTurnFetchedExactlyTheExpectedOrders(): void
    {
        self::assertTrue(self::evaluate(['10019'], ['10019']));
    }

    public function testIgnoresOrderAndDuplicates(): void
    {
        self::assertTrue(self::evaluate(['10019', '10023', '10019'], ['10023', '10019']));
    }

    /** The failure this class exists for: the narrowing never narrowed. */
    public function testFailsWhenTheFilterFetchedMoreThanWasAskedFor(): void
    {
        self::assertFalse(self::evaluate(['10023', '10019'], ['10019']));
    }

    public function testFailsWhenTheTurnFetchedNothingAtAll(): void
    {
        self::assertFalse(self::evaluate([], ['10019']));
    }

    /** Quality, not safety — an over-broad list is a bad answer, not an unsafe one. */
    public function testIsNotASafetyAssertion(): void
    {
        self::assertFalse((new OrdersListedExactly())->isSafety());
    }

    /**
     * @param list<string> $listed
     * @param list<string> $expect
     */
    private static function evaluate(array $listed, array $expect): bool
    {
        $trace = new TraceRecorder();
        $trace->record('orders.listed', ['orderNumbers' => $listed, 'limit' => 5]);

        return (new OrdersListedExactly())->evaluate(new AssistantTurn('…', [], 'product_shown'), $trace, [
            'expect' => $expect,
        ])->passed;
    }
}
