<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ProseAudit;

/**
 * The two ways a reply can contradict the cards printed beside it.
 *
 * Both sets of cases matter equally: the ones where the audit must fire, and the ones where it must
 * stay silent. Ruling R85 is why the second half is not optional — an assertion that flags correct
 * behaviour gets ignored, and these are the controls that must never be doubted.
 */
final class ProseAuditTest extends TestCase
{
    private function card(float $price, int $stock): ProductCard
    {
        return new ProductCard(
            id: 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2',
            parentId: 'fafafafafafafafafafafafafafafafa',
            name: 'Trail Jersey',
            description: null,
            price: $price,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/a2',
            imageUrl: null,
        );
    }

    public function testAPriceNoCardBacksIsFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedPrices('It costs 24.90 EUR.', [$this->card(74.90, 3)]);

        self::assertSame(['24.90'], $unbacked);
    }

    public function testACurrencyPrefixedFigureIsFlaggedToo(): void
    {
        // The form the eval actually caught. `CurrencyFigureExtractor` needs a currency token BEFORE
        // the number or two decimals — "40 EUR" is not matched, "EUR 40" is. Worth pinning, because
        // the first attempt at the shopper exemption was built on a wrong assumption about this.
        self::assertSame(['40'], (new ProseAudit())->unbackedPrices('Nothing over EUR 40 matched.', []));
    }

    public function testAPriceACardBacksIsNotFlaggedEvenWithoutDecimals(): void
    {
        // Compared in cents: "24" must match a card priced 24.00, or a reply that got the price
        // exactly right would be flagged.
        self::assertSame([], (new ProseAudit())->unbackedPrices('It costs 24 euros.', [$this->card(24.00, 3)]));
    }

    public function testAFigureTheShopperIntroducedIsNotTreatedAsAClaim(): void
    {
        // Ruling R85: the journey asks "nothing over 40 please" and the model restates the budget.
        // A currency-prefixed restatement is what the eval flagged, and the model had done nothing
        // wrong — flagging that trains people to ignore the one assertion that must never be doubted.
        //
        // Note the shopper's own "40" carries no currency token, so `CurrencyFigureExtractor` finds
        // nothing in it. That is exactly why the exemption reads the shopper's side with a plain
        // number scan; building it on the strict extractor made the fix silently inert.
        $unbacked = (new ProseAudit())->unbackedPrices(
            'I looked for brake pads under EUR 40 but the shop has no matching items.',
            [],
            'i need something to fix my brakes, nothing over 40 please',
        );

        self::assertSame([], $unbacked);
    }

    public function testTheShopperExemptionIsNumericAndNotASubstringMatch(): void
    {
        // A shopper writing "4000" must not silence a prose figure of "40".
        $unbacked = (new ProseAudit())->unbackedPrices('This one is EUR 40.', [], 'my budget is 4000');

        self::assertSame(['40'], $unbacked);
    }
}
