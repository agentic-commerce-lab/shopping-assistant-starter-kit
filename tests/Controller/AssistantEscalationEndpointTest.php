<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * The handoff at the wire, where a client actually reads it.
 *
 * `RecordingTurnRunner` returns a `product_shown` turn by default, so escalation is driven here by
 * setting its outcome rather than by prompting a model.
 */
final class AssistantEscalationEndpointTest extends AssistantEndpointTestCase
{
    public function testAnEscalatedTurnCarriesTheHandoff(): void
    {
        $controller = $this->controller($this->configuredWith([
            self::PREFIX . 'escalationUrl' => '/contact',
            self::PREFIX . 'escalationMessage' => 'Our team can help.',
        ]));
        $this->runner->outcome = 'escalated';

        $response = $controller->chat($this->post(['message' => 'where is my order?']), $this->context());
        $payload = $this->decode($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['message' => 'Our team can help.', 'url' => '/contact'], $payload['handoff']);
    }

    public function testAnOrdinaryTurnCarriesNoHandoff(): void
    {
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'escalationUrl' => '/contact']));

        $payload = $this->decode($controller->chat($this->post(['message' => 'a jersey']), $this->context()));

        self::assertArrayHasKey(
            'handoff',
            $payload,
            'the key is always present so a client need not branch on its absence',
        );
        self::assertNull($payload['handoff']);
    }

    public function testTheHandoffSurvivesAPageReload(): void
    {
        // A shopper who reloads must not lose the only route to a human they were given. History
        // rebuilds it from the stored per-turn outcome rather than persisting a second copy.
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'escalationUrl' => '/contact']));
        $this->runner->outcome = 'escalated';

        $token = $this->decode($controller->chat($this->post([
            'message' => 'where is my order?',
        ]), $this->context()))['token'];
        self::assertIsString($token);

        $messages = $this->decode($controller->history($this->get(['token' => $token]), $this->context()))['messages'];
        self::assertIsArray($messages);

        self::assertSame(
            ['message' => '', 'url' => '/contact'],
            AssistantTranscript::firstAssistantTurn($messages)['handoff'],
        );
    }

    public function testAWithdrawnDestinationIsNotStillOfferedInHistory(): void
    {
        // The stored turn still says `escalated`. The merchant has since switched escalation off, so
        // the reloaded transcript must not keep offering a route they withdrew.
        $controller = $this->controller($this->configuredWith([
            self::PREFIX . 'escalationUrl' => '/contact',
            self::PREFIX . 'enableEscalation' => false,
        ]));
        $this->runner->outcome = 'escalated';

        $token = $this->decode($controller->chat($this->post([
            'message' => 'where is my order?',
        ]), $this->context()))['token'];
        self::assertIsString($token);

        $messages = $this->decode($controller->history($this->get(['token' => $token]), $this->context()))['messages'];
        self::assertIsArray($messages);

        self::assertNull(AssistantTranscript::firstAssistantTurn($messages)['handoff']);
    }
}
