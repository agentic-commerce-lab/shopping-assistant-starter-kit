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
        public bool $logTraces = true,
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
