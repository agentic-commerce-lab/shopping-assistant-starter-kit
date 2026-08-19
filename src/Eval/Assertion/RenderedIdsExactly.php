<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * Bounds the rendered set of product ids FROM ABOVE, not just from below.
 *
 * Every other grounding assertion in this suite (`no_invented_product`,
 * `price_matches_source`, `stock_matches_source`) checks that specific expected ids
 * are present and correct — none of them fails when the run also rendered EXTRA,
 * unwanted ids. That gap is exactly how a case-casing bug in variant resolution
 * (see {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver}) slips past
 * this suite: asking for one specific variant and getting back three, two of them
 * wrong, still satisfies `price_matches_source`/`stock_matches_source` for the one
 * id they name. This assertion is the one that fails that run: `expect` is the
 * complete list of ids that must have been rendered, no more and no fewer.
 *
 * Reads {@see AssistantTurn::$cards}, which a multi-turn run's caller already
 * merges across every turn (see {@see \Swag\AssistantStarterKit\Eval\TurnAggregate}),
 * so this checks the whole run's rendered set, not just the last turn's.
 */
final class RenderedIdsExactly implements Assertion
{
    public function name(): string
    {
        return 'rendered_ids_exactly';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        /** @var list<string> $expect */
        $expect = $expectations['expect'] ?? [];

        $expected = self::sortedUnique($expect);
        $actual = self::sortedUnique(array_map(static fn(ProductCard $card): string => $card->id, $turn->cards));

        if ($expected === $actual) {
            return new AssertionResult(
                $this->name(),
                true,
                \sprintf('rendered ids match exactly: %s', implode(', ', $actual)),
            );
        }

        $extra = array_values(array_diff($actual, $expected));
        $missing = array_values(array_diff($expected, $actual));

        return new AssertionResult(
            $this->name(),
            false,
            \sprintf(
                'rendered ids did not match exactly — extra: [%s], missing: [%s]',
                implode(', ', $extra),
                implode(', ', $missing),
            ),
        );
    }

    public function isSafety(): bool
    {
        return true;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sortedUnique(array $ids): array
    {
        $unique = array_values(array_unique($ids));
        sort($unique);

        return $unique;
    }
}
