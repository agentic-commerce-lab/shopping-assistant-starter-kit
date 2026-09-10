<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\GivenDescriptions;
use Swag\AssistantStarterKit\Core\Tool\ShortlistDescriptions;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The boundary itself, away from a gateway and a fixture catalogue.
 *
 * Worth its own test because {@see ShortlistDescriptions::MAX_SURVIVORS} is a *measured* figure and
 * not a taste: against the trace export, `survivors` of one to three covers 15 of 60 searches, and
 * four or five moves that to 17 while paying for descriptions on searches where the shopper has
 * narrowed nothing. A change to the constant should have to argue with a failing test.
 *
 * The gate reads `survivors` — how many products matched — and never `count($returned)`. Those
 * differ on exactly the case that matters: 49 matches trimmed to 5 is a browse, not a shortlist, and
 * a gate on the returned count would call it one.
 */
final class ShortlistDescriptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function survivorCounts(): iterable
    {
        yield 'empty result is not a shortlist of nothing' => [0, false];
        yield 'one match' => [1, true];
        yield 'two matches' => [2, true];
        yield 'three matches, the documented bound' => [3, true];
        yield 'four matches is a browse' => [4, false];
        yield 'a wide match set' => [49, false];
    }

    #[DataProvider('survivorCounts')]
    public function testProseTravelsOnlyForAShortlist(int $survivors, bool $expected): void
    {
        $trace = new TraceRecorder();

        $products = ShortlistDescriptions::of([$this->card()], [], $survivors, $trace);

        self::assertSame(
            $expected,
            \array_key_exists('description', $products[0] ?? []),
            \sprintf('%d survivors', $survivors),
        );

        self::assertSame(
            $expected ? ['A 90 cm chain, supplied with two keys and no bracket.'] : [],
            GivenDescriptions::from($trace),
            'what the model was handed and what the trace says must not disagree',
        );
    }

    /**
     * A card the shop has nothing to say about gets no key and no recorded hand-over, even inside a
     * shortlist — {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary::withDescriptions()}
     * denies the model the inference an empty string would invite.
     */
    public function testASilentProductHandsOverNothing(): void
    {
        $trace = new TraceRecorder();

        $products = ShortlistDescriptions::of([$this->card(description: null)], [], 1, $trace);

        self::assertArrayNotHasKey('description', $products[0] ?? []);
        self::assertSame([], GivenDescriptions::from($trace));
    }

    private function card(?string $description = 'A 90 cm chain, supplied with two keys and no bracket.'): ProductCard
    {
        return new ProductCard(
            id: str_pad('chainlock', 32, '0'),
            parentId: null,
            name: 'Chain Lock 90cm',
            description: $description,
            price: 44.0,
            currency: 'EUR',
            stock: 11,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/chain-lock-90',
            imageUrl: null,
            properties: ['Material' => ['Steel']],
        );
    }
}
