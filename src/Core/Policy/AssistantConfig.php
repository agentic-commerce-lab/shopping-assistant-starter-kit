<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;

/**
 * The merchant's settings, as one value object.
 *
 * ## Zero means unlimited
 *
 * Four of these fields are limits, and on all four `0` means **no limit**. It used to mean the
 * opposite for the two rate windows — `dailyRequestCap: 0` refused every request — which made zero
 * the single most destructive value a merchant could type into a numeric field, in a form that
 * already has a deliberate off switch three cards above it. A setting whose empty-looking value
 * silently kills the shop is a trap, and it left *"unlimited"* with no way to express itself at all.
 *
 * `maxToolCallsPerTurn` is the exception and takes no zero: it bounds a model that has started
 * looping, not a merchant's budget. {@see \Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig}
 * floors it rather than clamping a typed number behind the merchant's back.
 *
 * ## `assistantEnabled`, not `killSwitch`
 *
 * Same control, stated in the direction the switch is drawn. A toggle whose ON position stops the
 * product reads as broken to everyone who has not read this file, and the admin form now renders it
 * with a confirmation dialog — which needs a label that means what the blue means.
 */
final readonly class AssistantConfig
{
    // @mago-expect lint:excessive-parameter-list
    // Each field is an independently merchant-tunable setting (voice, scope, cart limits,
    // the off switch, rate caps); grouping them into a sub-object would just move the same
    // flat settings behind another layer of indirection without reducing what a caller
    // needs to reason about.
    public function __construct(
        public string $agentVoice = '',
        public CatalogScope $scope = new CatalogScope(),
        public bool $enableAddToCart = true,
        public int $maxItemQuantity = 0,
        public float $maxCartValue = 0.0,
        public bool $assistantEnabled = true,
        public int $dailyRequestCap = 0,
        public int $maxToolCallsPerTurn = 20,
        public int $requestsPerMinute = 60,
        public bool $enableEscalation = true,
        public string $escalationUrl = '',
        public string $escalationMessage = '',
        public bool $enableMatchReasons = false,
        public bool $enableCompareProducts = false,
        public bool $logTraces = true,
        /**
         * The sales channel this config was built for.
         *
         * Not a setting — it is the identity of the scope every other value here belongs to. It
         * lives on the config because `SystemConfigAssistantConfig::forSalesChannel()` already knows
         * it, and because a tool that has the config then has the tenant without widening
         * `ToolContext`, which `docs/extending.md` presents as an extension point.
         *
         * Shop-info retrieval filters on it (spec R12): two channels can have different terms and
         * conditions, and a store query without this filter serves one channel's revocation notice
         * in another.
         */
        public string $salesChannelId = '',
        /**
         * The embedding model, empty when the merchant has not configured one.
         *
         * Empty means shop-info retrieval is entirely off: no tool in the toolbox, no ingestion
         * (spec R13). A starter kit offering a tool that always fails is worse than one offering no
         * tool.
         */
        public string $embeddingModel = '',
        /**
         * Whether editing one of the shop's own pages re-indexes it automatically.
         *
         * **Off by default, and the default is the decision.** Every content change would otherwise
         * spend embedding calls the merchant never asked for, and a shop reworking its terms over a
         * week would discover that on an invoice. On, it keeps a revocation notice from going stale —
         * which is the failure spec R8 is about, arriving through the CMS instead of through a
         * re-upload.
         */
        public bool $autoIndexShopPages = false,
        /**
         * The language to answer in when the shopper's own cannot be told — their first message
         * being a size, a colour or a bare product name.
         *
         * **A fallback, never a setting.** The reply follows the shopper: someone writing German
         * into an English-configured shop is still writing German, and answering them from the
         * settings screen would be the assistant correcting the person instead of serving them.
         * This is only what the shop opens with when there is nothing yet to follow.
         *
         * Resolved from the sales channel's locale by {@see \Swag\AssistantStarterKit\Core\Prompt\ReplyLanguage},
         * whose closed list is what keeps merchant-controlled text out of the system prompt. Empty
         * means unresolved, and {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt} reads it
         * as English — the same "empty is off" shape `embeddingModel` above uses, rather than a
         * second place that has to know what the default language is.
         */
        public string $defaultReplyLanguage = '',
    ) {}

    /**
     * Whether a cart quantity limit applies at all.
     *
     * Read through a named method rather than compared inline at each call site, so that "0 is
     * unlimited" is stated once and cannot be half-implemented by a later caller who compares the
     * raw field and accidentally blocks every add.
     */
    public function hasItemQuantityLimit(): bool
    {
        return $this->maxItemQuantity > 0;
    }

    public function hasCartValueLimit(): bool
    {
        return $this->maxCartValue > 0.0;
    }
}
