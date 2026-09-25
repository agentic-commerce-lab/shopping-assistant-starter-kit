<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Where `checkout_offered` ranks among the outcomes.
 *
 * This outcome used to be the only thing that carried the checkout link, chosen over a field on the
 * turn because the history endpoint rebuilds links from the stored outcome. That broke on the one
 * outcome ranked above it that does not cancel the offer: "add it and take me to checkout" is
 * `cart_added`, and the link never rendered. The offer now travels as its own fact
 * (`AssistantTurn::$checkoutOffered`); these tests pin the ranking, which the fix deliberately left
 * alone.
 */
final class TurnOutcomeCheckoutTest extends TestCase
{
    public function testAnOfferedCheckoutIsTheTurnsOutcome(): void
    {
        $trace = new TraceRecorder();
        $trace->record(GoToCheckoutTool::TRACE_STAGE, ['empty' => false]);

        self::assertSame(TurnOutcomeResolver::CHECKOUT_OFFERED, (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testAnEmptyCartOffersNoCheckoutAndSoIsNotTheOutcome(): void
    {
        // The link is rendered from this value, so a cart with nothing in it must not produce it:
        // a checkout link beside "your cart is empty" is the same empty promise the handoff
        // payload exists to prevent.
        $trace = new TraceRecorder();
        $trace->record(GoToCheckoutTool::TRACE_STAGE, ['empty' => true]);

        self::assertSame('no_result', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testEscalationStillOutranksIt(): void
    {
        // A turn that reached a human is about that, whatever else it did.
        $trace = new TraceRecorder();
        $trace->record(GoToCheckoutTool::TRACE_STAGE, ['empty' => false]);
        $trace->record('escalate', ['reason' => 'order status']);

        self::assertSame('escalated', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testACartAddStillOutranksIt(): void
    {
        // Not re-ranked to fix the missing link, on purpose: the widget refreshes the header cart
        // only on `cart_added`, and the funnel counts carts from it. The link comes from the offer.
        $trace = new TraceRecorder();
        $trace->record(AddToCartTool::TRACE_STAGE, ['name' => 'add_to_cart', 'policyReasonCode' => 'allowed']);
        $trace->record(GoToCheckoutTool::TRACE_STAGE, ['empty' => false]);

        self::assertSame('cart_added', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testItOutranksCardsHavingBeenShown(): void
    {
        // A shopper asking to check out has said what the turn was for. Cards, if any, are
        // whatever was already on screen.
        $trace = new TraceRecorder();
        $trace->record(GoToCheckoutTool::TRACE_STAGE, ['empty' => false]);

        self::assertSame(TurnOutcomeResolver::CHECKOUT_OFFERED, (new TurnOutcomeResolver())->outcome($trace, [self::card()]));
    }

    private static function card(): ProductCard
    {
        return new ProductCard(
            id: 'fx-026-blue-m',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 74.9,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/trail-jersey',
            imageUrl: null,
        );
    }
}
