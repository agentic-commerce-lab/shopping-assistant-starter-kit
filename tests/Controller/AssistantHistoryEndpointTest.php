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
 * Split out of {@see AssistantControllerTest} (too-many-methods) rather than suppressed.
 *
 * Covers `GET /assistant/history` — the endpoint that makes "add that to my cart" survive a page
 * reload, which `ARCHITECTURE.md` calls not optional and which is the reason persistence landed
 * before any interface.
 */
final class AssistantHistoryEndpointTest extends AssistantEndpointTestCase
{
    public function testHistoryReplaysAnExistingConversation(): void
    {
        $controller = $this->controller();
        $token = $this->store->start($this->guestScope(), 'en-GB');
        $this->store->append(
            $token,
            $this->guestScope(),
            new ConversationTurn(
                role: ConversationTurn::ROLE_ASSISTANT,
                prose: 'The Trail Jersey in Blue / M.',
                cardIds: ['a2'],
            ),
            new TraceRecorder(),
        );

        $payload = $this->decode($controller->history(
            Request::create('/assistant/history?token=' . $token),
            $this->context(),
        ));

        $messages = $payload['messages'];
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
    }

    public function testHistoryCarriesTheTurnsTimestamp(): void
    {
        // It exists so a re-hydrated conversation does not lose what the turn knew: the widget once
        // displayed the current time for a four-minute-old message.
        $controller = $this->controller();
        $token = $this->store->start($this->guestScope(), 'en-GB');
        $written = new \DateTimeImmutable('2026-08-20T09:41:07+00:00');

        $this->store->append(
            $token,
            $this->guestScope(),
            new ConversationTurn(
                role: ConversationTurn::ROLE_ASSISTANT,
                prose: 'Yes, the Trail Jersey is available in Blue, size M.',
                cardIds: [self::BLUE_M_ID],
                outcome: 'product_shown',
                createdAt: $written,
            ),
            new TraceRecorder(),
        );

        $payload = $this->decode($controller->history(
            Request::create('/assistant/history?token=' . $token),
            $this->context(),
        ));
        $messages = $payload['messages'];
        self::assertIsArray($messages);
        $message = $messages[0];
        self::assertIsArray($message);

        self::assertSame($written->format(\DATE_ATOM), $message['createdAt']);
        // No grounding warning travels with a turn any more — see
        // `AssistantControllerTest::testTheResponseCarriesNoGroundingWarnings()`.
        self::assertArrayNotHasKey('warnings', $message);
    }

    public function testATurnStoredBeforeTimestampsExistedReportsNullRatherThanNow(): void
    {
        // A client must render no timestamp for this, never the current time — that would present a
        // figure this server never produced as fact.
        $controller = $this->controller();
        $token = $this->store->start($this->guestScope(), 'en-GB');
        $this->store->append(
            $token,
            $this->guestScope(),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'show me the trail jersey'),
            new TraceRecorder(),
        );

        $payload = $this->decode($controller->history(
            Request::create('/assistant/history?token=' . $token),
            $this->context(),
        ));
        $messages = $payload['messages'];
        self::assertIsArray($messages);
        $message = $messages[0];
        self::assertIsArray($message);

        self::assertNull($message['createdAt']);
    }

    public function testHistoryWithNoTokenIsAnEmptyConversationRatherThanAnError(): void
    {
        // The first page load of a shopper who has never spoken to the assistant.
        $payload = $this->decode($this->controller()->history(Request::create('/assistant/history'), $this->context()));

        self::assertSame([], $payload['messages']);
    }

    public function testAMalformedTokenIsIgnoredRatherThanReachingARepositoryLookup(): void
    {
        $payload = $this->decode($this->controller()->history(
            Request::create('/assistant/history?token=not-a-token'),
            $this->context(),
        ));

        self::assertSame([], $payload['messages']);
    }

    public function testAForeignTokenReadsAsAnEmptyConversationRatherThanDisclosingItExists(): void
    {
        // `chat()` has this case ({@see AssistantControllerTest::testAForeignTokenGetsAFreshConversationInsteadOfThrowing});
        // this endpoint did not. A shopper who logs in mid-conversation, or who has a stale token
        // from a different scope, must see the same empty history a token that was never issued
        // gets — not an error, and nothing in the payload that would let a client tell the two cases
        // apart.
        $controller = $this->controller();
        $foreignToken = $this->store->start(
            new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::CUSTOMER),
            'en-GB',
        );
        $this->store->append(
            $foreignToken,
            new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, self::CUSTOMER),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'not yours'),
            new TraceRecorder(),
        );

        $payload = $this->decode($controller->history(
            Request::create('/assistant/history?token=' . $foreignToken),
            $this->context(),
        ));

        self::assertSame(['messages' => []], $payload);
    }
}
