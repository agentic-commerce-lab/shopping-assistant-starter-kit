<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ProseAudit;

/**
 * A currency figure the shop's OWN retrieved document contains is not an unbacked claim.
 *
 * ## The defect this closes
 *
 * Measured 2026-08-27 through the real endpoint on the local shop. Asked *"what are your shipping
 * costs?"*, the assistant answered correctly from the shop's own shipping document — and the response
 * carried:
 *
 *     "warnings": {"unbackedPrices": ["4.95", "29.00", "9.95", "14.95"]}
 *
 * Every figure was right. `unbackedPrices()` compares prose figures against the prices of RENDERED
 * CARDS, a shop-information turn renders none, and spec R6 explicitly allows the model to paraphrase
 * retrieved passage text. So every legitimate figure in every shop-information answer was unbacked by
 * construction, and the widget annotated a correct answer as suspect.
 *
 * That is the failure this class's own docblock forbids: *"A safety assertion that fires on correct
 * behaviour trains people to ignore it, which is unaffordable on this one."* Exemption R85 already
 * exists for figures the SHOPPER introduced, for exactly that reason; a figure the shop's own document
 * introduced is the same situation with a different source.
 *
 * ## Why the passages given, not every passage retrieved
 *
 * The exemption takes the passages the model was actually handed — what
 * `RetrievedPassages::from($trace)` returns. Exempting a figure from a passage scored below the
 * threshold and never shown would weaken the one thing this check is for: catching a figure the model
 * was not given. The tighter reading costs nothing, because that is the set already available.
 */
final class PassageFigureExemptionTest extends TestCase
{
    public function testAFigureFromAGivenPassageIsNotFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedPrices(
            'Within Germany a flat 4.95 EUR applies, free from 75.00 EUR.',
            [],
            '',
            ['Shipping within Germany costs 4.95 EUR per order. Orders of 75.00 EUR and above ship free.'],
        );

        self::assertSame([], $unbacked);
    }

    /** The measured case, verbatim: four figures, one document, no cards. */
    public function testTheMeasuredShippingAnswerIsClean(): void
    {
        $unbacked = (new ProseAudit())->unbackedPrices(
            'Within Germany, a flat shipping charge of 4.95 euro applies per order. Bulky items carry '
            . 'a surcharge of 29.00 euro. Austria and the Netherlands cost 9.95 euro, other EU '
            . 'countries 14.95 euro.',
            [],
            '',
            ['Germany: 4.95 EUR flat, bulky surcharge 29.00 EUR. AT/NL 9.95 EUR. Other EU 14.95 EUR.'],
        );

        self::assertSame([], $unbacked);
    }

    /** The control: a figure in NO passage and on NO card is still flagged. */
    public function testAFigureInNoPassageIsStillFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedPrices(
            'Shipping is 3.50 EUR.',
            [],
            '',
            ['Shipping within Germany costs 4.95 EUR per order.'],
        );

        self::assertSame(['3.50'], $unbacked);
    }

    /** Passing no passages must behave exactly as before, or every product turn changes meaning. */
    public function testProductTurnsAreUnaffected(): void
    {
        $card = new ProductCard(
            id: 'p1',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 69.90,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/p1',
            imageUrl: null,
        );

        self::assertSame([], (new ProseAudit())->unbackedPrices('It is 69.90 EUR.', [$card]));
        self::assertSame(['99.00'], (new ProseAudit())->unbackedPrices('It is 99.00 EUR.', [$card]));
    }
}
