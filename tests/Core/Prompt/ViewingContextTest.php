<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Prompt\ViewingContext;

final class ViewingContextTest extends TestCase
{
    public function testNamesTheProductTheShopperIsLookingAt(): void
    {
        $line = ViewingContext::line(self::card());

        self::assertStringContainsString('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', $line);
        self::assertStringContainsString('Trail Jersey', $line);
        self::assertStringContainsString('Colour: Blue', $line);
        self::assertStringContainsString('Size: M', $line);
    }

    /**
     * The rule this class exists to enforce. Option values are already in the prompt through the
     * catalogue vocabulary, so naming them opens no new fabrication surface — a price does. D3's
     * substance is that the model never supplies a figure, and a figure it read in its own prompt
     * is a figure it can quote without earning it.
     *
     * **What is banned is every value the card carries, not the words for them.** The line says in
     * so many words that the assistant has no price and no stock here and must use its tools, and
     * that sentence is the point — a ban on the substring `stock` would forbid the instruction
     * while permitting `74.90`, which is precisely backwards. The list is derived from the card so
     * it cannot drift away from the fixture it is meant to guard.
     */
    public function testNeverCarriesAFigure(): void
    {
        $card = self::card();
        $line = ViewingContext::line($card);

        $figures = [
            '74.9',
            '74,9',
            (string) $card->stock,
            $card->currency,
            (string) $card->deliveryTime,
            'in stock',
        ];

        foreach ($figures as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $line);
        }
    }

    public function testSaysNothingWhenThereIsNoProduct(): void
    {
        self::assertSame('', ViewingContext::line(null));
    }

    public function testAProductWithNoOptionsStillNames(): void
    {
        $line = ViewingContext::line(self::card(options: []));

        self::assertStringContainsString('Trail Jersey', $line);
    }

    /**
     * @param array<string, string> $options
     */
    private static function card(array $options = ['Colour' => 'Blue', 'Size' => 'M']): ProductCard
    {
        return new ProductCard(
            id: 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1',
            parentId: null,
            name: 'Trail Jersey',
            description: 'Lightweight long-sleeve jersey.',
            price: 74.90,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: '2-3 days',
            url: 'https://example.test/detail/a1',
            imageUrl: null,
            options: $options,
        );
    }

    /**
     * The bug this argument exists for, measured on the staging shop 2026-09-02.
     *
     * A detail page sends the id of the **variant** the shopper has selected, not the parent — the
     * storefront template says so outright, and it is the right id for "is this in stock?" and for
     * add-to-cart. But this line then told the model that the product on screen is `Size: M` and, in
     * the same breath, **not to call a tool to look it up**. Asked *"which sizes are available?"* the
     * model obeyed both instructions and answered from the only size it had been given:
     *
     * > "The A-Line Bag 3317 is specifically available in size L. There are no other sizes currently
     * > listed for this product."
     *
     * Confidently wrong, correctly grounded, and invisible in the trace — worse than a hallucination.
     * The same model answered the same question correctly with no page context at all, because then
     * it had to search. So the fix is not to make it search: it is to stop the line being the reason
     * it does not have to.
     *
     * Values only, never a figure — the family's option values are in the catalogue vocabulary
     * already, exactly like the viewed card's own.
     */
    public function testTheFamilysOtherOptionValuesAreNamedAlongsideTheSelectedOne(): void
    {
        $line = ViewingContext::line(self::card(), ['Size' => ['XS', 'S', 'M', 'L', 'XL']]);

        self::assertStringContainsString('Size: M', $line, 'the selected variant is still named');

        foreach (['XS', 'S', 'L', 'XL'] as $sibling) {
            self::assertStringContainsString($sibling, $line);
        }
    }

    public function testAProductWithNoFamilyReadsExactlyAsItDidBefore(): void
    {
        // A standalone product has no siblings to name, and a line that gained an empty clause would
        // spend prompt tokens saying nothing on every CMS and listing page in the shop.
        self::assertSame(ViewingContext::line(self::card()), ViewingContext::line(self::card(), []));
    }

    public function testAFamilyWithOnlyTheSelectedValueAddsNothing(): void
    {
        // One variant that happens to have a parent is not a choice. Naming "available in Size: M"
        // beside "you are looking at Size: M" reads as two different facts and invites the model to
        // treat the repetition as significant.
        self::assertSame(
            ViewingContext::line(self::card()),
            ViewingContext::line(self::card(), ['Size' => ['M'], 'Colour' => ['Blue']]),
        );
    }
}
