<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;
use Swag\AssistantStarterKit\Core\Retrieval\SuperlativeSort;

/**
 * The shop reads the superlative, because the model did not.
 *
 * Measured on a 6.6 test shop, 2026-09-03, gemini-3.7-flash: "what is the cheapest coat?" named a
 * coat at 155.71 beside rendered cards of 155.71 / 71.07 / 135.42, three samples running, with
 * `filtersApplied: []` and no sort every time.
 */
final class SuperlativeSortTest extends TestCase
{
    /**
     * @return iterable<string, array{string, PriceSort|null}>
     */
    public static function messageProvider(): iterable
    {
        yield 'the reported question' => ['what is the cheapest coat?', PriceSort::Ascending];
        yield 'least expensive' => ['show me the least expensive jersey', PriceSort::Ascending];
        yield 'lowest priced' => ['the lowest-priced helmet please', PriceSort::Ascending];
        yield 'German, declined' => ['was ist der günstigste Mantel?', PriceSort::Ascending];
        yield 'German, another ending' => ['zeig mir die günstigsten Trikots', PriceSort::Ascending];
        yield 'German, ue spelling' => ['der guenstigste Helm', PriceSort::Ascending];
        yield 'billigste' => ['welches ist das billigste Trikot?', PriceSort::Ascending];

        yield 'most expensive' => ['what is the most expensive coat?', PriceSort::Descending];
        yield 'priciest' => ['your priciest saddle', PriceSort::Descending];
        yield 'teuerste' => ['der teuerste Sattel', PriceSort::Descending];

        // A comparative asks relative to what is already on screen, which is a different question
        // with a different answer. Only the absolute form is claimed here.
        yield 'a comparative is not a superlative' => ['do you have anything cheaper?', null];
        yield 'German comparative' => ['hast du etwas günstigeres?', null];
        yield 'an ordinary product question' => ['do you have a blue jersey in size M?', null];
        // `cheapest` inside a longer word must not match, the reason the patterns carry boundaries.
        yield 'not inside a longer word' => ['is this the cheapestcoat range?', null];
    }

    #[DataProvider('messageProvider')]
    public function testItReadsTheOrderingTheShopperAskedFor(string $message, ?PriceSort $expected): void
    {
        self::assertSame($expected, SuperlativeSort::inferredFrom($message));
    }

    /**
     * Ascending wins a sentence carrying both, so the outcome does not depend on array order.
     */
    public function testAscendingWinsWhenBothAppear(): void
    {
        self::assertSame(
            PriceSort::Ascending,
            SuperlativeSort::inferredFrom('the cheapest and the most expensive one'),
        );
    }
}
