<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

/**
 * Reason codes are machine-readable so traces can be filtered and counted.
 * Known codes: blocked_product, blocked_category, capability_disabled,
 * not_implemented, kill_switch, cart_limit, invalid_arguments.
 *
 * `daily_cap` used to be here. It belongs to {@see RequestBudget} now, which refuses a request
 * before it becomes a turn — so there is no turn for a trace to explain, and the caller is told over
 * HTTP instead. See {@see GuardCheck} for why the move was the fix rather than a regression.
 */
final readonly class PolicyDecision
{
    public function __construct(
        public PolicyVerdict $verdict,
        public string $reasonCode,
        public string $message,
    ) {}

    public static function allow(): self
    {
        return new self(PolicyVerdict::Allow, 'allowed', 'Allowed.');
    }

    public static function block(string $reasonCode, string $message): self
    {
        return new self(PolicyVerdict::Block, $reasonCode, $message);
    }

    public function isBlocked(): bool
    {
        return $this->verdict === PolicyVerdict::Block;
    }
}
