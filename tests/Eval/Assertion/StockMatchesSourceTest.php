<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\StockMatchesSource;
use Swag\AssistantStarterKit\Tests\Support\BuildsEvalCards;

/**
 * Reads `variant.resolve`'s presence in the trace and the `stock`/`stockSource` fields
 * of the turn's rendered cards — never the prose.
 */
final class StockMatchesSourceTest extends TestCase
{
    use BuildsEvalCards;

    /**
     * The control that now carries the whole burden of the leak {@see StockMatchesSource}
     * exists to catch: a card with the right id but the wrong {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource}
     * still fails, regardless of whether a `variant.resolve` trace event exists — no
     * event is recorded here at all, unlike before this fix.
     */
    public function testFailsWhenStockCameFromTheParent(): void
    {
        $result = (new StockMatchesSource())->evaluate($this->turnWithParentStock(), new TraceRecorder(), [
            'scope' => 'variant',
            'expect' => ['fx-026-blue-m' => 0],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('parent', $result->detail);
    }

    /**
     * The regression this fix closes: {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver::resolve()}
     * returns early without recording when there are no selections to resolve, and a
     * plain search can hand back the correct variant card directly with no
     * `variant.resolve` event at all — yet the answer is exactly right. Requiring that
     * stage previously failed this turn even though the card carries the correct id and
     * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Variant}.
     */
    public function testPassesWithNoVariantResolveEventWhenTheCardIsAlreadyCorrect(): void
    {
        $result = (new StockMatchesSource())->evaluate($this->turnWithCard('fx-026-blue-m'), new TraceRecorder(), [
            'scope' => 'variant',
            'expect' => ['fx-026-blue-m' => 0],
        ]);

        self::assertTrue($result->passed);
    }

    /**
     * Ruling this project's whole eval design exists to enforce: an empty `expect` map
     * under `scope === 'variant'` must not pass vacuously by looping zero times.
     */
    public function testFailsWithEmptyExpectMapUnderVariantScope(): void
    {
        $result = (new StockMatchesSource())->evaluate($this->turnWithCard('fx-026-blue-m'), new TraceRecorder(), [
            'scope' => 'variant',
            'expect' => [],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('no expected card/stock pairs', $result->detail);
    }

    public function testPassesWhenTheCardIsTheResolvedVariant(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $variant = $gateway->product('fx-026-blue-m', new CatalogScope());
        self::assertNotNull($variant);

        $trace = new TraceRecorder();
        $trace->record('variant.resolve', [
            'attempts' => [[
                'parentId' => 'fx-026',
                'variantId' => 'fx-026-blue-m',
                'priceRefetched' => true,
                'stockRefetched' => true,
            ]],
        ]);

        $turn = new AssistantTurn(prose: '', cards: [$variant], outcome: 'product_shown');

        $result = (new StockMatchesSource())->evaluate($turn, $trace, [
            'scope' => 'variant',
            'expect' => ['fx-026-blue-m' => 0],
        ]);

        self::assertTrue($result->passed);
    }

    public function testIsSafetyAndNamed(): void
    {
        self::assertTrue((new StockMatchesSource())->isSafety());
        self::assertSame('stock_matches_source', (new StockMatchesSource())->name());
    }
}
