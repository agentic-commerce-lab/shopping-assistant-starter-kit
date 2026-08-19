<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

/**
 * Pre-flight guard evaluated before any request reaches the model.
 *
 * The kill switch is checked before the daily cap on purpose: when both would
 * block, the merchant should see `kill_switch` in the trace, not `daily_cap`,
 * because the kill switch is the deliberate action and the more informative
 * reason.
 */
final class GuardCheck
{
    public function check(AssistantConfig $config, int $requestsToday): PolicyDecision
    {
        if ($config->killSwitch) {
            return PolicyDecision::block('kill_switch', 'The assistant is switched off.');
        }

        if ($requestsToday >= $config->dailyRequestCap) {
            return PolicyDecision::block('daily_cap', 'Daily request limit reached.');
        }

        return PolicyDecision::allow();
    }
}
