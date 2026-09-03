<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The controller's job is **ordering**: validate, check configuration, run (the guard fires inside
 * the runner, before any spend), persist, then answer from the rendered cards. Every step here has a
 * failure mode, and the point of the {@see ChatTurnRunnerInterface} seam is that all of them are
 * observable without an LLM, a network or a Shopware kernel.
 */
final class AssistantControllerTest extends AssistantEndpointTestCase
{
    public function testAnUnconfiguredShopAnswersPolitelyInsteadOfCrashing(): void
    {
        $response = $this->controller([])->chat($this->post(['message' => 'hi']), $this->context());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(0, $this->runner->calls, 'A missing API key must not be discovered mid-turn.');
    }

    public function testAFreshConversationGetsATokenTheClientCanKeep(): void
    {
        $payload = $this->decode($this->controller()->chat($this->post(['message' => 'hi']), $this->context()));

        self::assertArrayHasKey('token', $payload);
        self::assertIsString($payload['token']);
        self::assertNotSame('', $payload['token']);
    }

    public function testAnExistingTokenContinuesTheSameConversationRatherThanStartingANewOne(): void
    {
        $controller = $this->controller();

        $first = $this->decode($controller->chat($this->post(['message' => 'one']), $this->context()));
        $token = $first['token'];
        self::assertIsString($token);

        $second = $this->decode($controller->chat($this->post([
            'message' => 'two',
            'token' => $token,
        ]), $this->context()));

        self::assertSame($token, $second['token']);
        // Two turns each: the shopper's message and the assistant's reply.
        self::assertCount(4, $this->store->history($token, $this->guestScope()));
    }

    public function testThePreviousTurnsAreHandedToTheRunnerSoThatStillResolves(): void
    {
        // "add that to my cart" after a page reload is the whole reason persistence lands before
        // the UI. If history never reaches the runner, "that" has no antecedent.
        $controller = $this->controller();

        $first = $this->decode($controller->chat($this->post(['message' => 'one']), $this->context()));
        $token = $first['token'];
        self::assertIsString($token);

        $controller->chat($this->post(['message' => 'add that to my cart', 'token' => $token]), $this->context());

        self::assertNotSame([], $this->runner->lastHistory);
    }

    public function testTheResponseCarriesRenderedCardFiguresAndNeverParsesTheProse(): void
    {
        // D3 arriving at the wire: the model supplies words, the shop supplies numbers. The seeded
        // Blue/M is sold out at its own 74.90, which the parent would report as 35 at 79.90.
        $payload = $this->decode($this->controller()->chat($this->post([
            'message' => 'blue jersey in M',
        ]), $this->context()));

        $cards = $payload['cards'];
        self::assertIsArray($cards);
        $card = $cards[0] ?? null;
        self::assertIsArray($card);

        self::assertSame(self::BLUE_M_ID, $card['id']);
        self::assertSame(74.90, $card['price']);
        self::assertSame(0, $card['stock']);
        self::assertSame('variant', $card['stockSource']);
        self::assertFalse($card['inStock']);
    }

    public function testTheTraceOfEveryTurnIsPersisted(): void
    {
        // Acceptance criterion A6.
        $payload = $this->decode($this->controller()->chat($this->post(['message' => 'hi']), $this->context()));
        $token = $payload['token'];
        self::assertIsString($token);

        self::assertNotSame([], $this->store->traceEvents($token));
    }

    public function testTheResponseCarriesNoGroundingWarnings(): void
    {
        // Removed 2026-09-03, and asserted absent rather than simply deleted: the audit behind it
        // still runs and still writes `claims.audit`, so re-exposing it here is a one-line change
        // someone could make in good faith. Every occurrence reported from a live shop was a false
        // positive — `GroundingOutputProcessor` records five, dated, against no true one — and a
        // notice that is wrong more often than right costs trust in the correct answers beside it.
        //
        // The guarantee it appeared to provide is structural and untouched: the cards are the only
        // figures on screen, and `FactRenderer` renders them from the catalogue.
        $payload = $this->decode($this->controller()->chat($this->post(['message' => 'hi']), $this->context()));

        self::assertIsArray($payload);
        self::assertArrayNotHasKey('warnings', $payload);
    }

    public function testAForeignTokenGetsAFreshConversationInsteadOfThrowing(): void
    {
        // The regression this branch exists to prevent: before it, a stale token — or a guest token
        // presented again after the shopper logged in — reached append() unchanged, and
        // DalConversationStore::append() throws ForeignConversationException on a scope mismatch,
        // turning an ordinary "guest logs in mid-conversation" flow into a 500 on every message.
        $controller = $this->controller();
        $foreignToken = $this->store->start(
            new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::CUSTOMER),
            'en-GB',
        );

        $response = $controller->chat($this->post(['message' => 'hi', 'token' => $foreignToken]), $this->context());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = $this->decode($response);
        self::assertIsString($payload['token']);
        self::assertNotSame($foreignToken, $payload['token']);
    }
}
