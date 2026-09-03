<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Tool\GoToCheckoutTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The two defects this tool exists to close, both reported from a running shop on 2026-09-03.
 *
 * **"I want to go to checkout" reached the shopper as a promise and a wrong link.** The rules end on
 * "You cannot … create orders, take payment" and the escalation clause says "If asked about any of
 * those, escalate" — so checkout escalated, and `HandoffPayload` rendered the merchant's *contact*
 * page beside a reply that said a checkout link followed. Nothing had one.
 *
 * **A cart filled by the card's own button was invisible.** That button posts to Shopware's
 * `frontend.checkout.line-item.add` and tells this plugin nothing; the agent's only cart input was
 * `cartAvailable: true`, a capability flag. So the model answered from its own memory of the
 * conversation — it had added nothing, therefore the cart was empty — and said so to a shopper
 * looking at a filled cart.
 *
 * Reading the cart is the whole job. The URL is not here and never reaches the model: it is rendered
 * server-side by {@see \Swag\AssistantStarterKit\Controller\CheckoutPayload} from the outcome, the
 * same rule prices and the contact link already follow (D3).
 */
final class GoToCheckoutToolTest extends TestCase
{
    private static function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testReportsAFilledCartAsReadyAndAnnouncesTheLinkThatFollows(): void
    {
        $gateway = self::gateway();
        $gateway->addToCart('fx-001', 1);
        $trace = new TraceRecorder();

        $result = (new GoToCheckoutTool($gateway, $trace))();

        self::assertFalse($result['empty']);
        self::assertFalse($trace->payload(GoToCheckoutTool::TRACE_STAGE)['empty']);
    }

    public function testReportsAnEmptyCartWithoutAnnouncingALink(): void
    {
        // The reply must not promise a link the server will not render. "Here is a link to the
        // checkout" with nothing under it is the reported defect, in the one case where it would
        // still be true that no link exists.
        $trace = new TraceRecorder();

        $result = (new GoToCheckoutTool(self::gateway(), $trace))();

        self::assertTrue($result['empty']);
        self::assertTrue($trace->payload(GoToCheckoutTool::TRACE_STAGE)['empty']);
        self::assertStringNotContainsStringIgnoringCase('link follows', $result['note']);
    }

    public function testTheModelIsNeverHandedTheCheckoutUrlOrTheCartsFigures(): void
    {
        // The rules forbid the model from stating a URL, a price or a count. Handing it any of them
        // and then forbidding their use is what produced "here is a link to the checkout" with no
        // link: the only output that satisfies both instructions.
        $gateway = self::gateway();
        $gateway->addToCart('fx-001', 2);

        $result = (new GoToCheckoutTool($gateway, new TraceRecorder()))();

        self::assertSame(['empty', 'note'], array_keys($result));
        self::assertStringNotContainsString('/checkout', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testTheNoteForbidsWritingAUrlAndSayingWhatTheCartHolds(): void
    {
        // Same discipline as EscalateTool's note, and for the same measured reason: the wording is
        // what the model paraphrases, so a note that leaves the door open gets walked through.
        $gateway = self::gateway();
        $gateway->addToCart('fx-001', 1);

        $note = (new GoToCheckoutTool($gateway, new TraceRecorder()))()['note'];

        self::assertStringContainsStringIgnoringCase('link follows', $note);
        self::assertStringContainsStringIgnoringCase('do not write a url', $note);
    }
}
