<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

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
        $token = $this->store->start(self::CHANNEL, 'en-GB');
        $this->store->append(
            $token,
            new ConversationTurn(ConversationTurn::ROLE_ASSISTANT, 'The Trail Jersey in Blue / M.', ['a2']),
            new TraceRecorder(),
        );

        $payload = $this->decode($controller->history(Request::create('/assistant/history?token=' . $token)));

        $messages = $payload['messages'];
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
    }

    public function testHistoryWithNoTokenIsAnEmptyConversationRatherThanAnError(): void
    {
        // The first page load of a shopper who has never spoken to the assistant.
        $payload = $this->decode($this->controller()->history(Request::create('/assistant/history')));

        self::assertSame([], $payload['messages']);
    }

    public function testAMalformedTokenIsIgnoredRatherThanReachingARepositoryLookup(): void
    {
        $payload = $this->decode($this->controller()->history(Request::create('/assistant/history?token=not-a-token')));

        self::assertSame([], $payload['messages']);
    }
}
