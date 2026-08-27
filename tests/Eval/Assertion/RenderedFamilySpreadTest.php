<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\RenderedFamilySpread;

/**
 * `rendered_family_spread` — the rendered cards span at least `min` distinct families, not just
 * relevance-ranked variants of the same one or two products. See
 * `docs/superpowers/specs/2026-08-27-family-diversified-narrowing-design.md`.
 */
final class RenderedFamilySpreadTest extends TestCase
{
    public function testPassesWhenTheFloorIsMet(): void
    {
        $turn = self::turnWithFamilies(['a', 'a', 'b', 'c']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 3]);

        self::assertTrue($result->passed);
    }

    public function testFailsWhenTheFloorIsNotMet(): void
    {
        // Exactly the failure this assertion exists to catch: five cards, one family.
        $turn = self::turnWithFamilies(['a', 'a', 'a', 'a', 'a']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 3]);

        self::assertFalse($result->passed);
        self::assertStringContainsString('1 distinct', $result->detail);
        self::assertStringContainsString('3', $result->detail);
    }

    public function testStandaloneProductsEachCountAsTheirOwnFamily(): void
    {
        $turn = self::turnWithFamilies([null, null, null]);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 3]);

        self::assertTrue($result->passed);
    }

    public function testFailsWithoutAnIntegerMin(): void
    {
        $turn = self::turnWithFamilies(['a', 'b']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), []);

        self::assertFalse($result->passed);
    }

    public function testFailsWithAMinBelowTwo(): void
    {
        // A floor of 1 (or 0) asserts nothing about spread — any non-empty render satisfies it.
        $turn = self::turnWithFamilies(['a']);

        $result = (new RenderedFamilySpread())->evaluate($turn, new TraceRecorder(), ['min' => 1]);

        self::assertFalse($result->passed);
    }

    public function testIsNotSafetyAndIsNamed(): void
    {
        self::assertFalse((new RenderedFamilySpread())->isSafety());
        self::assertSame('rendered_family_spread', (new RenderedFamilySpread())->name());
    }

    /**
     * @param list<string|null> $parentIds one card per entry; null means standalone
     */
    private static function turnWithFamilies(array $parentIds): AssistantTurn
    {
        $cards = [];

        foreach ($parentIds as $index => $parentId) {
            $id = $parentId === null ? 'standalone-' . $index : $parentId . '-' . $index;

            $cards[] = new ProductCard(
                id: $id,
                parentId: $parentId,
                name: 'Card',
                description: null,
                price: 10.0,
                currency: 'EUR',
                stock: 5,
                stockSource: $parentId === null ? StockSource::Product : StockSource::Variant,
                deliveryTime: null,
                url: '/detail/' . $id,
                imageUrl: null,
            );
        }

        return new AssistantTurn(prose: 'Here is what I found.', cards: $cards, outcome: 'product_shown');
    }
}
