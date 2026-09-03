<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Swag\AssistantStarterKit\Core\Agent\TurnOutcomeResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * The checkout link at the wire, where the widget actually reads it.
 *
 * Reported from a running shop on 2026-09-03: "I want to go to checkout" was answered with a
 * sentence promising a checkout link and, beneath it, the merchant's *contact* page. The prose was
 * the model's; the wrong link was this endpoint's, because `escalated` was the only outcome that
 * carried a link at all and the prompt sent checkout down that branch.
 *
 * `RecordingTurnRunner` returns `product_shown` by default, so the outcome is set here rather than
 * prompting a model for it.
 */
final class AssistantCheckoutEndpointTest extends AssistantEndpointTestCase
{
    public function testACheckoutTurnCarriesTheShopsOwnCheckoutUrl(): void
    {
        $controller = $this->controller();
        $this->runner->outcome = TurnOutcomeResolver::CHECKOUT_OFFERED;

        $response = $controller->chat($this->post(['message' => 'take me to checkout']), $this->context());
        $payload = $this->decode($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['url' => '/checkout/confirm'], $payload['checkout']);
    }

    public function testACheckoutTurnCarriesNoContactHandoff(): void
    {
        // The reported defect, at the wire: the shopper got the contact page for asking to pay.
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'escalationUrl' => '/contact']));
        $this->runner->outcome = TurnOutcomeResolver::CHECKOUT_OFFERED;

        $payload = $this->decode($controller->chat($this->post(['message' => 'checkout']), $this->context()));

        self::assertNull($payload['handoff']);
    }

    public function testAnOrdinaryTurnCarriesNoCheckoutLink(): void
    {
        $payload = $this->decode($this->controller()->chat($this->post(['message' => 'a jersey']), $this->context()));

        self::assertArrayHasKey(
            'checkout',
            $payload,
            'the key is always present so a client need not branch on its absence',
        );
        self::assertNull($payload['checkout']);
    }

    public function testAnEscalatedTurnStillCarriesTheContactLinkAndNoCheckoutLink(): void
    {
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'escalationUrl' => '/contact']));
        $this->runner->outcome = 'escalated';

        $payload = $this->decode($controller->chat($this->post(['message' => 'where is my order?']), $this->context()));

        self::assertNull($payload['checkout']);
        self::assertSame(['message' => '', 'url' => '/contact'], $payload['handoff']);
    }

    public function testTheCheckoutLinkSurvivesAPageReload(): void
    {
        // Rebuilt from the stored outcome, which is why the outcome carries it: a shopper who
        // reloads mid-checkout must not lose the one link they were given.
        $controller = $this->controller();
        $this->runner->outcome = TurnOutcomeResolver::CHECKOUT_OFFERED;

        $token = $this->decode($controller->chat($this->post([
            'message' => 'take me to checkout',
        ]), $this->context()))['token'];
        self::assertIsString($token);

        $messages = $this->decode($controller->history($this->get(['token' => $token]), $this->context()))['messages'];
        self::assertIsArray($messages);

        self::assertSame(
            ['url' => '/checkout/confirm'],
            AssistantTranscript::firstAssistantTurn($messages)['checkout'],
        );
    }
}
