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
 * `POST /assistant/chat` is a **public** endpoint that spends money on every accepted request, so
 * what it refuses — and refuses *before* reaching the model — is a security property rather than
 * input hygiene.
 */
final class AssistantChatValidationTest extends AssistantEndpointTestCase
{
    public function testAMissingMessageIsRejectedBeforeAnyModelSpend(): void
    {
        $response = $this->controller()->chat($this->post([]), $this->context());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, $this->runner->calls, 'A malformed request must not reach the model.');
    }

    public function testAnOversizedMessageIsRejectedRatherThanTruncated(): void
    {
        // The endpoint is public, so its input bound is a security control rather than a nicety —
        // and truncating would send the model half a question and answer it confidently.
        $response = $this->controller()->chat($this->post(['message' => str_repeat('a', 5000)]), $this->context());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(0, $this->runner->calls);
    }
}
