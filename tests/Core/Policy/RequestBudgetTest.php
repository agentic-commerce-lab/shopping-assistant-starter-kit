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

    public function testAZeroLimitMeansUnlimitedRatherThanRefusingEveryRequest(): void
    {
        // **This assertion is the inverse of the one it replaced, and the reversal is the fix.**
        //
        // A stored 0 used to refuse everything, which made zero the most destructive value a
        // merchant could type into a numeric field — reachable by clearing a box, and describing
        // itself in the form as a limit. It was also redundant: the assistant already has a
        // deliberate off switch, and `GuardCheck` is what answers "this shop is switched off" with
        // a reason a trace can record. What zero *could not* express, and now does, is the thing
        // merchants actually want from a spend ceiling they never asked for: no ceiling.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: 0, requestsPerMinute: 0);

        foreach (range(1, 200) as $ignored) {
            self::assertTrue($budget->consumeClientWindow($config, 'client-a')->accepted);
            self::assertTrue($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);
        }
    }

    public function testANegativeLimitIsUnlimitedTooRatherThanRejectingEverything(): void
    {
        // `SystemConfigAssistantConfig` already folds negatives into 0 before they get here, so this
        // pins the second line of defence: a caller constructing an AssistantConfig directly — the
        // probe command, the eval suite, a test — must not be able to produce a budget that refuses
        // unconditionally by passing a number no form could have produced.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: -1, requestsPerMinute: -1);

        self::assertTrue($budget->consumeClientWindow($config, 'client-a')->accepted);
        self::assertTrue($budget->consumeDailyBudget($config, self::CHANNEL)->accepted);
    }

    public function testARejectionAlwaysCarriesARetryAfterOfAtLeastOneSecond(): void
    {
        // The value goes into a `Retry-After` header. Zero would invite an immediate retry, which is
        // the request this verdict just refused.
        //
        // A real limit has to be exhausted to get a rejection now — there is no longer a setting
        // that refuses on the first call, which is the point of the two tests above.
        $budget = new RequestBudget(new InMemoryStorage());
        $config = new AssistantConfig(dailyRequestCap: 1, requestsPerMinute: 1);

        $budget->consumeClientWindow($config, 'client-a');
        $budget->consumeDailyBudget($config, self::CHANNEL);

        self::assertGreaterThanOrEqual(1, $budget->consumeClientWindow($config, 'client-a')->retryAfterSeconds);
        self::assertGreaterThanOrEqual(1, $budget->consumeDailyBudget($config, self::CHANNEL)->retryAfterSeconds);
    }
}
