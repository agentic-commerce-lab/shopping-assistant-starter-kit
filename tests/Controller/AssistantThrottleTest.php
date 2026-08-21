<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /assistant/chat` is public and spends model tokens per call, so what this file asserts is
 * that a refused request costs the merchant *nothing*: no model call, and no row written.
 *
 * The order of the two limits is also asserted here, because it is a decision rather than an
 * accident. The client window is consumed first and unconditionally — it is the abuse defence, and
 * skipping it in any branch creates an unthrottled path. The daily budget is consumed after the kill
 * switch has had its say, so a merchant who switched the assistant off is told that, rather than
 * being told their budget ran out.
 */
final class AssistantThrottleTest extends AssistantEndpointTestCase
{
    public function testAClientPastItsWindowIsRefusedWithoutReachingTheModel(): void
    {
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'requestsPerMinute' => 2]));

        self::assertSame(
            Response::HTTP_OK,
            $controller->chat($this->post(['message' => 'one']), $this->context())->getStatusCode(),
        );
        self::assertSame(
            Response::HTTP_OK,
            $controller->chat($this->post(['message' => 'two']), $this->context())->getStatusCode(),
        );

        $response = $controller->chat($this->post(['message' => 'three']), $this->context());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame(2, $this->runner->calls, 'the refused request must never reach the model');
    }

    public function testARefusedRequestCarriesARetryAfterHeader(): void
    {
        // Without it a client has nothing to back off by, and the widget's retry button becomes a
        // way to hammer the endpoint that just refused it.
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'requestsPerMinute' => 1]));

        $controller->chat($this->post(['message' => 'one']), $this->context());
        $response = $controller->chat($this->post(['message' => 'two']), $this->context());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
    }

    public function testARefusedRequestStartsNoConversationAndWritesNoTurn(): void
    {
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'requestsPerMinute' => 0]));

        $response = $controller->chat($this->post(['message' => 'hello']), $this->context());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame(0, $this->store->startedConversations, 'a refused request must write nothing');
        self::assertSame(0, $this->runner->calls);
    }

    public function testTwoShoppersDoNotShareOneWindow(): void
    {
        // A shared window would make the throttle the denial of service it exists to prevent: the
        // first busy shopper would lock out the shop.
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'requestsPerMinute' => 1]));

        $controller->chat($this->post(['message' => 'one'], ip: '198.51.100.7'), $this->context());
        $refused = $controller->chat($this->post(['message' => 'two'], ip: '198.51.100.7'), $this->context());
        $other = $controller->chat($this->post(['message' => 'one'], ip: '203.0.113.9'), $this->context());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $refused->getStatusCode());
        self::assertSame(Response::HTTP_OK, $other->getStatusCode());
    }

    public function testAnExhaustedDailyBudgetRefusesTheChannelWithoutReachingTheModel(): void
    {
        $controller = $this->controller($this->configuredWith([self::PREFIX . 'dailyRequestCap' => 1]));

        self::assertSame(
            Response::HTTP_OK,
            $controller->chat($this->post(['message' => 'one']), $this->context())->getStatusCode(),
        );

        $response = $controller->chat($this->post(['message' => 'two']), $this->context());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame(1, $this->runner->calls);
        self::assertSame(1, $this->store->startedConversations);
    }

    public function testASwitchedOffAssistantIsAnsweredByTheRunnerRatherThanByTheBudget(): void
    {
        // `killSwitch` help text promises the trace records why it stopped, and `GuardCheck` is what
        // writes that. Consuming the daily budget first would answer 429 to a shop that is simply
        // switched off — the wrong reason, and one that never reaches a trace at all.
        $controller = $this->controller($this->configuredWith([
            self::PREFIX . 'killSwitch' => true,
            self::PREFIX . 'dailyRequestCap' => 0,
        ]));

        $response = $controller->chat($this->post(['message' => 'hello']), $this->context());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $this->runner->calls, 'the kill switch is the runner’s decision to report');
    }

    public function testASwitchedOffAssistantIsStillThrottledPerClient(): void
    {
        // The branch above must not become an unthrottled path: the kill switch stops model spend,
        // not the database writes a request still performs on its way to being refused.
        $controller = $this->controller($this->configuredWith([
            self::PREFIX . 'killSwitch' => true,
            self::PREFIX . 'requestsPerMinute' => 1,
        ]));

        $controller->chat($this->post(['message' => 'one']), $this->context());
        $response = $controller->chat($this->post(['message' => 'two']), $this->context());

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }
}
