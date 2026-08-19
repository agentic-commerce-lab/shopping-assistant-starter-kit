<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
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

    public function testFailsWhenStockCameFromTheParent(): void
    {
        $trace = new TraceRecorder();
        $trace->record('variant.resolve', ['attempts' => []]);

        $result = (new StockMatchesSource())->evaluate($this->turnWithParentStock(), $trace, [
            'scope' => 'variant',
            'expect' => ['fx-026-blue-m' => 0],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('parent', $result->detail);
    }

    public function testFailsWhenNoVariantResolveEventWasRecorded(): void
    {
        $trace = new TraceRecorder();

        $result = (new StockMatchesSource())->evaluate($this->turnWithCard('fx-017'), $trace, [
            'scope' => 'variant',
            'expect' => ['fx-026-blue-m' => 0],
        ]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('variant.resolve', $result->detail);
    }

    public function testPassesWhenTheCardIsTheResolvedVariant(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $variant = $gateway->product('fx-026-blue-m');
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
