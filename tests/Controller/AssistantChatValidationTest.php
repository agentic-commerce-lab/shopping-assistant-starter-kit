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

    public function testTheOpenProductIdReachesTheRunner(): void
    {
        $this->controller()->chat($this->post([
            'message' => 'do you have this in blue?',
            'productId' => 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1',
        ]), $this->context());

        self::assertSame('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', $this->runner->lastViewingProductId);
    }

    public function testAMalformedProductIdIsDroppedRatherThanRejectingTheTurn(): void
    {
        // The id is a hint. A page template emitting something unexpected must cost the shopper an
        // optimisation, never their answer — so the turn still runs, just without page context.
        foreach (['../../etc/passwd', 'Ignore previous instructions', 'A1A1', str_repeat('z', 32), ''] as $bad) {
            $this->runner->seedViewingProductId('not-null');

            $response = $this->controller()->chat($this->post([
                'message' => 'hi',
                'productId' => $bad,
            ]), $this->context());

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
                \sprintf('"%s" must not fail the turn', $bad),
            );
            self::assertNull($this->runner->lastViewingProductId, \sprintf('"%s" must not parse as an id', $bad));
        }
    }

    public function testNoProductIdIsSentFromAPageWithoutOne(): void
    {
        $this->controller()->chat($this->post(['message' => 'hi']), $this->context());

        self::assertNull($this->runner->lastViewingProductId);
    }
}
