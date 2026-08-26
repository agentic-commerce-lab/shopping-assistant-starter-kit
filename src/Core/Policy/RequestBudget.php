<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * The two limits standing in front of `POST /assistant/chat`, which is public and spends model
 * tokens on every call.
 *
 * They answer different questions and must not be collapsed into one:
 *
 * - **The client window** is the abuse defence. It is per caller and short, and it is the only
 *   control that stops a scripted loop. It is consumed *unconditionally*, before any database read
 *   or write — a defence that first stores something is an amplifier, not a defence. It ships **on**,
 *   at 60 a minute: that is roughly twenty times what a shopper typing can produce and still bounds
 *   a script, which is the shape a default on a public endpoint should have.
 * - **The daily budget** is the merchant's spend ceiling, per sales channel. It ships **off**,
 *   because a ceiling nobody chose is not a safety feature — the old default of 500 turned a good
 *   day's traffic into a dead assistant by mid-afternoon, with no error a merchant could see. On its
 *   own it would also be a denial-of-service vector: one script could burn a whole day's budget and
 *   leave real shoppers with nothing until it reset. It is safe only *because* the client window
 *   bounds who can spend it, which is why the client window is the one that stays on.
 *
 * `dailyRequestCap` used to be enforced nowhere at all: {@see GuardCheck} implemented the comparison
 * correctly, but the storefront path constructed
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner} without ever supplying a count, so the
 * parameter kept its default of 0 and the cap could not trip. The field sat in the merchant's
 * settings describing itself as a security control while enforcing nothing. Counting lives here now,
 * in the one place that also consumes it, so the two cannot drift apart again.
 *
 * **Storage is a cache, not the database.** Counting rows would mean a query per request on the
 * public endpoint, and the retention task deletes those rows daily, so the count would be wrong by
 * design. The trade is that clearing the shop's cache resets both windows — acceptable for a
 * throttle, and cheap enough that no request pays for a read it does not need.
 */
final readonly class RequestBudget
{
    public const REASON_CLIENT_RATE = 'client_rate';

    public const REASON_DAILY_CAP = 'daily_cap';

    private const MINUTE_SECONDS = 60;

    private const DAY_SECONDS = 86400;

    public function __construct(
        private StorageInterface $storage,
    ) {}

    /**
     * @param string $clientKey opaque, already hashed — see {@see \Swag\AssistantStarterKit\Controller\ClientKey}
     */
    public function consumeClientWindow(AssistantConfig $config, string $clientKey): BudgetVerdict
    {
        // Sliding rather than fixed: a fixed window lets a caller spend its whole allowance at the
        // end of one window and again at the start of the next, which is twice the limit back to back.
        return $this->consume(
            new RateWindow(
                id: 'swag_assistant_client',
                policy: 'sliding_window',
                limit: $config->requestsPerMinute,
                seconds: self::MINUTE_SECONDS,
                reasonCode: self::REASON_CLIENT_RATE,
            ),
            $clientKey,
        );
    }

    public function consumeDailyBudget(AssistantConfig $config, string $salesChannelId): BudgetVerdict
    {
        // Fixed: a spend ceiling should refill in one step at a predictable moment rather than
        // trickling back, so a merchant watching it sees "reset" and not "slowly recovering". The
        // window opens on the first request rather than at midnight, which is what the setting's
        // help text says.
        return $this->consume(
            new RateWindow(
                id: 'swag_assistant_daily',
                policy: 'fixed_window',
                limit: $config->dailyRequestCap,
                seconds: self::DAY_SECONDS,
                reasonCode: self::REASON_DAILY_CAP,
            ),
            $salesChannelId,
        );
    }

    /**
     * One body for both windows: they differ only in policy, size and what a rejection is called.
     *
     * No `LockFactory` is passed. Two simultaneous requests can each read the same window state and
     * both be accepted, so a caller can exceed its limit by roughly the number of requests it has
     * genuinely in flight. That is a rounding error against the loop this exists to stop, and a lock
     * per request on a public endpoint costs more than it saves.
     */
    private function consume(RateWindow $window, string $key): BudgetVerdict
    {
        if ($window->limit < 1) {
            // **Zero means unlimited, and it used to mean the opposite.**
            //
            // A stored 0 refused every request. That made zero the most destructive value a merchant
            // could type into a numeric field — in a form that already carries a deliberate off
            // switch, so the behaviour was not even reachable except by accident. Worse, it left
            // *"do not limit this"* with nothing to express itself as: a merchant who wanted no
            // daily ceiling had to invent a number large enough to never trip.
            //
            // Answered here rather than by the limiter, which would otherwise need a token consumed
            // against an infinite limit on every request for no result.
            return BudgetVerdict::accept();
        }

        $factory = new RateLimiterFactory([
            'id' => $window->id,
            'policy' => $window->policy,
            'limit' => $window->limit,
            'interval' => \sprintf('%d seconds', $window->seconds),
        ], $this->storage);

        $rateLimit = $factory->create($key)->consume();

        if ($rateLimit->isAccepted()) {
            return BudgetVerdict::accept();
        }

        return BudgetVerdict::reject($window->reasonCode, $rateLimit->getRetryAfter()->getTimestamp() - time());
    }
}
