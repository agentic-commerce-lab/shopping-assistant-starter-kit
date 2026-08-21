<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

/**
 * Pre-flight guard evaluated before any request reaches the model.
 *
 * **The daily cap is not checked here any more, and that is the fix rather than a regression.** This
 * class used to compare a `$requestsToday` argument against `dailyRequestCap`, and the comparison
 * was correct — but no caller ever supplied a count.
 * {@see \Swag\AssistantStarterKit\Core\Agent\ShopwareChatTurnRunner} constructed
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner} without it, the parameter kept its
 * default of 0, and the cap could only ever trip when a merchant set it to 0 — the one value meaning
 * "refuse everything". A setting describing itself as a security control enforced nothing.
 *
 * Counting a public endpoint's requests cannot happen here in any case: this runs per *turn*, inside
 * the request it is meant to bound, after the conversation row has been written. It now lives in
 * {@see RequestBudget}, at the HTTP boundary, where a refusal costs neither a database write nor a
 * model call. Keeping a second copy here is what let the two drift apart in the first place.
 *
 * What remains is the kill switch, which needs no count and belongs per turn: it is the merchant's
 * deliberate off switch, and every entry point — the storefront, the probe command, the eval suite —
 * must honour it.
 */
final class GuardCheck
{
    public function check(AssistantConfig $config): PolicyDecision
    {
        if ($config->killSwitch) {
            return PolicyDecision::block('kill_switch', 'The assistant is switched off.');
        }

        return PolicyDecision::allow();
    }
}
