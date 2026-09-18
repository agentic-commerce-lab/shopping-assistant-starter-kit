<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Bounds the orders a turn FETCHED, which is the only way to see whether a filter did anything.
 *
 * {@see RenderedIdsExactly}'s argument, moved one layer earlier. The safety assertions on an order
 * journey — {@see NoForeignOrderInProse} above all — check that the reply names nothing the turn did
 * not fetch. **They are all satisfied by a filter that silently does nothing**, because fetching
 * more than was asked for is not, by itself, a lie.
 *
 * So a narrowed question needs this: asked for the OPEN orders of a shopper who has one open and one
 * shipped, `orders.listed` must carry exactly the open one. Both of these pass every safety
 * assertion and only one of them is right:
 *
 * - `['10019']` — the filter reached the query.
 * - `['10023', '10019']` — the model never passed `state`, or the gateway ignored it.
 *
 * **It reads the trace, not the prose or the cards**, so it measures the TOOL rather than the
 * sentence: whether the model chose to narrow, and whether narrowing worked. Those are the two ways
 * phase 3 can fail, and neither shows up anywhere else.
 *
 * Quality rather than safety, deliberately. A turn that lists too many of the shopper's OWN orders
 * has answered a question badly; it has not shown anybody something that is not theirs. Ruling R85's
 * rule applies — a control that fires on a merely-unhelpful turn is one people learn to ignore.
 */
final class OrdersListedExactly implements Assertion
{
    public function name(): string
    {
        return 'orders_listed_exactly';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        /** @var list<string> $expect */
        $expect = $expectations['expect'] ?? [];

        $recorded = $trace->payload('orders.listed');
        $listed = $recorded['orderNumbers'] ?? [];

        /** @var list<string> $fetched */
        $fetched = \is_array($listed) ? array_values(array_map(strval(...), $listed)) : [];

        $actual = self::sortedUnique($fetched);
        $expected = self::sortedUnique($expect);

        if ($expected === $actual) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('the turn fetched exactly: %s', implode(', ', $actual) ?: '(nothing)'),
            );
        }

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'the turn fetched the wrong set — extra: [%s], missing: [%s]. %s',
                implode(', ', array_values(array_diff($actual, $expected))),
                implode(', ', array_values(array_diff($expected, $actual))),
                OrderQueryAsked::describe($recorded),
            ),
        );
    }

    public function isSafety(): bool
    {
        return false;
    }

    /**
     * @param list<string> $numbers
     *
     * @return list<string>
     */
    private static function sortedUnique(array $numbers): array
    {
        $unique = array_values(array_unique($numbers));
        sort($unique);

        return $unique;
    }
}
