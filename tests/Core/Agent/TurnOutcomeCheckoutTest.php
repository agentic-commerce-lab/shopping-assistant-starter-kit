<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The outcome is what carries the checkout link, exactly as `escalated` carries the contact link.
 *
 * Chosen over a new field on the turn because the history endpoint rebuilds the handoff from the
 * stored outcome — `AssistantController::history()` — so a reloaded conversation gets its link back
 * for free, and nothing new has to be written into the transcript.
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
