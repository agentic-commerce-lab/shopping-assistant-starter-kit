<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * A turn that showed the shopper five of their orders is not a turn with no result.
 *
 * Found live, on the local shop with real orders: asked for open orders, the assistant returned five
 * cards and the turn was recorded `no_result`. The cards render either way — the payload does not
 * read the outcome — so nothing on screen was wrong, and that is exactly why it would have survived:
 * the damage is in the trace and everything downstream of it. A merchant reading their history sees
 * a failed turn; the nightly insights count it as one.
 *
 * `HandoffPayload` and `CheckoutPayload` also key on the outcome. Neither renders for `no_result`,
 * which happened to be right here — by accident rather than by decision, and accidents like that
 * stop being right when somebody adds a third payload.
 */
final class OrdersTurnOutcomeTest extends TestCase
{
    public function testListingOrdersIsItsOwnOutcome(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.listed', ['orderNumbers' => ['10023', '10019'], 'limit' => 5]);

        self::assertSame(TurnOutcomeResolver::ORDERS_SHOWN, (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testShowingOneOrderIsToo(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.detail', ['orderNumber' => '10023', 'found' => true, 'lineCount' => 2]);

        self::assertSame(TurnOutcomeResolver::ORDERS_SHOWN, (new TurnOutcomeResolver())->outcome($trace, []));
    }

    /** Looking and finding nothing really is no result — the shopper was told so honestly. */
    public function testFindingNoneIsStillNoResult(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.listed', ['orderNumbers' => [], 'limit' => 5]);

        self::assertSame('no_result', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    public function testAnOrderThatCouldNotBeFoundIsNoResult(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.detail', ['orderNumber' => '99999', 'found' => false]);

        self::assertSame('no_result', (new TurnOutcomeResolver())->outcome($trace, []));
    }

    /** Escalation still wins: it is terminal, and a turn that handed off is a handoff. */
    public function testEscalationStillWins(): void
    {
        $trace = new TraceRecorder();
        $trace->record('orders.listed', ['orderNumbers' => ['10023'], 'limit' => 5]);
        $trace->record('escalate', []);

        self::assertSame('escalated', (new TurnOutcomeResolver())->outcome($trace, []));
    }
}
