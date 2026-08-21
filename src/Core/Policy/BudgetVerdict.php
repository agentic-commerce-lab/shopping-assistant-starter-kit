<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

/**
 * The outcome of asking {@see RequestBudget} whether one request may proceed.
 *
 * Separate from {@see PolicyDecision} on purpose: that one describes a *turn* the assistant
 * declined to take and carries prose for the shopper, while this one describes a request that never
 * became a turn and carries the one thing an HTTP client needs instead — how long to wait.
 */
final readonly class BudgetVerdict
{
    private function __construct(
        public bool $accepted,
        public string $reasonCode,
        public int $retryAfterSeconds,
    ) {}

    public static function accept(): self
    {
        return new self(true, '', 0);
    }

    /**
     * @param int $retryAfterSeconds floored at 1 — this value becomes a `Retry-After` header, and 0
     *                               invites the immediate retry this verdict just refused
     */
    public static function reject(string $reasonCode, int $retryAfterSeconds): self
    {
        return new self(false, $reasonCode, max(1, $retryAfterSeconds));
    }
}
