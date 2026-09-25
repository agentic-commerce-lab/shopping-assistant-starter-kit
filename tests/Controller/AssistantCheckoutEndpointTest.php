<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * prompting a model for it — and so is the checkout offer, which the real runner reads from the
 * trace (see `CheckoutBesideACartAddTest`).
 */
final class AssistantCheckoutEndpointTest extends AssistantEndpointTestCase
{
    public function testACheckoutTurnCarriesTheShopsOwnCheckoutUrl(): void
    {
        $controller = $this->controller();
        $this->runner->outcome = TurnOutcomeResolver::CHECKOUT_OFFERED;
        $this->runner->checkoutOffered = true;

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
        $this->runner->checkoutOffered = true;

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
        // Even when the same turn found a filled cart: a turn that reached for a human is about
        // that, and the contact block is the one thing beside it.
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'escalationUrl' => '/contact']));
        $this->runner->outcome = 'escalated';
        $this->runner->checkoutOffered = true;

        $payload = $this->decode($controller->chat($this->post(['message' => 'where is my order?']), $this->context()));

        self::assertNull($payload['checkout']);
        self::assertSame(['message' => '', 'url' => '/contact'], $payload['handoff']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outcomesThatCarryTheLink(): iterable
    {
        yield 'a checkout turn' => [TurnOutcomeResolver::CHECKOUT_OFFERED];

        // The stored outcome says `cart_added` and nothing else, so the offer has to be stored
        // beside it — otherwise the reload drops a link the live reply showed.
        yield 'an add followed by checkout in the same turn' => ['cart_added'];
    }

    #[DataProvider('outcomesThatCarryTheLink')]
    public function testTheCheckoutLinkSurvivesAPageReload(string $outcome): void
    {
        // Rebuilt from what was stored with the turn: a shopper who reloads mid-checkout must not
        // lose the one link they were given.
        $controller = $this->controller();
        $this->runner->outcome = $outcome;
        $this->runner->checkoutOffered = true;

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

    public function testATurnThatAddedAndThenOfferedCheckoutCarriesTheLinkAndKeepsItsCartOutcome(): void
    {
        // Found in production traces: "add it and take me to checkout" ran both tools, the note told
        // the model a link followed, and none did — `cart_added` outranks `checkout_offered`, and
        // the link keyed off the outcome alone. The outcome must stay `cart_added`: it is what makes
        // the widget refresh the header cart.
        $controller = $this->controller();
        $this->runner->outcome = 'cart_added';
        $this->runner->checkoutOffered = true;

        $payload = $this->decode($controller->chat($this->post([
            'message' => 'add it and take me to checkout',
        ]), $this->context()));

        self::assertSame('cart_added', $payload['outcome']);
        self::assertSame(['url' => '/checkout/confirm'], $payload['checkout']);
    }

    public function testACartAddAloneCarriesNoCheckoutLink(): void
    {
        // The link follows the offer, not the add: "put it in my cart" did not ask to check out.
        $controller = $this->controller();
        $this->runner->outcome = 'cart_added';

        $payload = $this->decode($controller->chat($this->post(['message' => 'add it']), $this->context()));

        self::assertNull($payload['checkout']);
    }
}
