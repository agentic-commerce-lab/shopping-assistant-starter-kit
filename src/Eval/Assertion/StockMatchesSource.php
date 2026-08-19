<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Eval\Assertion;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion;
use Swag\AssistantStarterKit\Eval\AssertionResult;

/**
 * The direct check for the failure {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver}
 * exists to prevent: reporting a parent product's aggregate stock in answer to a variant
 * question. `expect` maps a rendered card id to the stock figure it must carry; when
 * `scope === 'variant'`, the card must also carry {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Variant}
 * — a card with the right id but `Parent` means the figure leaked from the aggregate
 * even though the id looks correct. That per-card check is
 * {@see VariantStockCheck::evaluate()}, split out to keep this class's own
 * cyclomatic-complexity total under this project's threshold.
 *
 * This assertion deliberately does NOT also require a `variant.resolve` trace event
 * when `scope === 'variant'`. {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver::resolve()}
 * returns early, without recording, when there are no selections to resolve — and
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway::search()} indexes
 * only sellable units, so a plain search can hand back the correct variant card
 * directly, with no `resolveVariant()` call and therefore no `variant.resolve` event,
 * even though the answer is exactly right. Requiring that stage failed a turn that was
 * actually correct: it checks *how* the variant was reached, not *whether* the right
 * one was.
 *
 * The per-card `StockSource::Variant` check above is a strict superset of that stage
 * requirement, catching the same leak regardless of which route produced the card:
 * resolution ran but the prose quoted the parent figure — caught by the prose-vs-card
 * comparison this class's sibling assertions perform; no resolution and search
 * returned the parent card — caught here, the id or the `stockSource` is wrong; no
 * card at all — caught, the per-card lookup in {@see VariantStockCheck::evaluate()}
 * fails outright. Nothing the stage requirement caught is missed by the data check.
 *
 * The one thing the stage requirement incidentally provided — refusing to pass
 * vacuously when there was nothing to check — is now its own explicit guard below: a
 * `scope === 'variant'` turn with an empty `expect` map fails, rather than looping zero
 * times and reporting success.
 */
final class StockMatchesSource implements Assertion
{
    public function name(): string
    {
        return 'stock_matches_source';
    }

    public function evaluate(AssistantTurn $turn, TraceRecorder $trace, array $expectations): AssertionResult
    {
        /** @var array<string, int> $expect */
        $expect = $expectations['expect'] ?? [];
        $scope = $expectations['scope'] ?? null;

        if ('variant' === $scope && [] === $expect) {
            return new AssertionResult(
                $this->name(),
                false,
                'scope is "variant" but no expected card/stock pairs were given — '
                . 'this assertion would otherwise pass without checking anything',
            );
        }

        foreach ($expect as $expectedId => $expectedStock) {
            $result = VariantStockCheck::evaluate($this->name(), $turn, $expectedId, (int) $expectedStock, $scope);

            if (!$result->passed) {
                return $result;
            }
        }

        return new AssertionResult($this->name(), true, 'stock matches its source for every expected card');
    }

    public function isSafety(): bool
    {
        return true;
    }
}
