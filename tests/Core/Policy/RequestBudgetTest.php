<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Policy;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\RequestBudget;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * `POST /assistant/chat` is public and spends model tokens per call, so these two windows are the
 * only thing between a merchant's key and a `while true` loop.
 *
 * Both are asserted here rather than through the controller because the interesting property is
 * *separation*: the per-client window must bound one abuser without touching anyone else, and it
 * must not silently consume the merchant's daily budget on the way.
 */
final class RequestBudgetTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const OTHER_CHANNEL = '02b02c5bf6678395bd0ffc4727609bd4';

    public function testAClientIsAllowedUpToItsLimitAndRejectedOnTheNextRequest(): void
    {
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(requestsPerMinute: 3);

        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue(
                $budget->consumeClientWindow($config, 'client-a')->accepted,
                \sprintf('request %d of 3 should be within the window', $i),
            );
        }

        $verdict = $budget->consumeClientWindow($config, 'client-a');

        self::assertFalse($verdict->accepted);
        self::assertSame(RequestBudget::REASON_CLIENT_RATE, $verdict->reasonCode);
        self::assertGreaterThan(0, $verdict->retryAfterSeconds);
    }

    public function testOneClientExhaustingItsWindowLeavesAnotherClientUnaffected(): void
    {
        // The failure this rules out is a shared window, which would turn the throttle itself into
        // the denial of service it exists to prevent: one script would lock out every shopper.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(requestsPerMinute: 1);

        self::assertTrue($budget->consumeClientWindow($config, 'client-a')->accepted);
        self::assertFalse($budget->consumeClientWindow($config, 'client-a')->accepted);

        self::assertTrue($budget->consumeClientWindow($config, 'client-b')->accepted);
    }

    public function testTheDailyBudgetIsCountedPerSalesChannelAndNotGlobally(): void
    {
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: 2);

        self::assertTrue($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);
        self::assertTrue($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);

        $verdict = $budget->consumeDailyBudget($config, self::CHANNEL);
        self::assertFalse($verdict->accepted);
        self::assertSame(RequestBudget::REASON_DAILY_CAP, $verdict->reasonCode);

        // A second sales channel has its own budget: the cap is a per-channel merchant setting, and
        // one channel's traffic must not switch off another's assistant.
        self::assertTrue($budget->consumeDailyBudget($config, self::OTHER_CHANNEL)->accepted);
    }

    public function testTheClientWindowDoesNotConsumeTheDailyBudget(): void
    {
        // Both limiters share one storage, so an id collision would make every client request also
        // spend a day's budget — the cap would then read as reached after a handful of messages.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: 1, requestsPerMinute: 5);

        $budget->consumeClientWindow($config, 'client-a');
        $budget->consumeClientWindow($config, 'client-a');
        $budget->consumeClientWindow($config, 'client-a');

        self::assertTrue($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);
        self::assertFalse($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);
    }

    public function testAZeroLimitRefusesEveryRequestRatherThanMeaningUnlimited(): void
    {
        // `SystemConfigAssistantConfig` maps an *absent* setting to the documented default, so a
        // stored 0 is a deliberate "refuse everything" — the same reading its docblock already gives
        // `dailyRequestCap: 0`. Treating 0 as unlimited would invert a merchant's intent.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: 0, requestsPerMinute: 0);

        self::assertFalse($budget->consumeClientWindow($config, 'client-a')->accepted);
        self::assertFalse($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);
    }

    public function testARejectionAlwaysCarriesARetryAfterOfAtLeastOneSecond(): void
    {
        // The value goes into a `Retry-After` header. Zero would invite an immediate retry, which is
        // the request this verdict just refused.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: 0, requestsPerMinute: 0);

        self::assertGreaterThanOrEqual(1, $budget->consumeClientWindow($config, 'client-a')->retryAfterSeconds);
        self::assertGreaterThanOrEqual(1, $budget->consumeDailyBudget($config, self::CHANNEL)->retryAfterSeconds);
    }
}
