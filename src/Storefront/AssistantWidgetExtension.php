<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Storefront;

use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings;
use Swag\AssistantStarterKit\Core\Config\WidgetTheme;
use Swag\AssistantStarterKit\Core\Context\ContextStorageKey;
use Swag\AssistantStarterKit\Core\Context\ShoppingContextResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Everything the widget's templates need to know, and nothing else.
 *
 * ## Why the gate lives in PHP
 *
 * `isConfigured()` already encodes what *"this shop can answer"* means — all three credentials
 * present, environment overriding stored config — and `assistantEnabled` already encodes *"stop
 * everything"*. Re-deriving either in Twig would give a one-owner contract a second owner, which is
 * the duplication AGENTS.md's shared-contracts rule exists to prevent. `SystemConfigLlmSettings`
 * even names this caller in its own doc block: *"the widget needs it to stay hidden on a shop that
 * never configured a model."*
 *
 * ## Why hiding matters more than it looks
 *
 * **An orb that opens a panel which answers 503 is worse than no orb.** It invites a shopper to ask
 * a question that nothing can answer, and the failure surfaces as a broken interface rather than as
 * the configuration problem it is. Three independent conditions each render nothing, and the
 * distinction between them is deliberate: an unconfigured shop *cannot* answer, a killed assistant
 * *must not*, and a merchant who switched the widget off has only declined this interface — the
 * endpoint stays reachable for their own.
 */
final class AssistantWidgetExtension extends AbstractExtension
{
    public function __construct(
        private readonly SystemConfigLlmSettings $llmSettings,
        private readonly SystemConfigAssistantConfig $assistantConfig,
        private readonly SystemConfigWidgetSettings $widgetSettings,
        private readonly ShoppingContextResolver $shoppingContext,
        private readonly ContextStorageKey $storageKey,
    ) {}

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('swag_assistant_widget_enabled', $this->isEnabled(...)),
            new TwigFunction('swag_assistant_widget_name', $this->assistantName(...)),
            new TwigFunction('swag_assistant_greeting', $this->greeting(...)),
            new TwigFunction('swag_assistant_add_to_cart_enabled', $this->addToCartEnabled(...)),
            new TwigFunction('swag_assistant_theme', $this->theme(...)),
            new TwigFunction('swag_assistant_context_key', $this->contextKey(...)),
        ];
    }

    public function isEnabled(string $salesChannelId): bool
    {
        // Cheapest check first, and the one a merchant controls most directly.
        if (!$this->widgetSettings->isWidgetEnabled($salesChannelId)) {
            return false;
        }

        if (!$this->llmSettings->isConfigured($salesChannelId)) {
            return false;
        }

        return $this->assistantConfig->forSalesChannel($salesChannelId)->assistantEnabled;
    }

    public function theme(string $salesChannelId): WidgetTheme
    {
        return $this->widgetSettings->theme($salesChannelId);
    }

    public function assistantName(string $salesChannelId): string
    {
        return $this->widgetSettings->assistantName($salesChannelId);
    }

    /**
     * `$locale` comes from the template, which reads it off the request — the same value it puts in
     * `data-locale` for price formatting, so the greeting and the numbers beside it can never
     * disagree about which storefront this is. Absent, the fallback language's greeting applies; see
     * {@see SystemConfigWidgetSettings::greeting()}.
     */
    public function greeting(string $salesChannelId, ?string $locale = null): string
    {
        return $this->widgetSettings->greeting($salesChannelId, $locale);
    }

    /**
     * Whether a product card may offer an add-to-cart button.
     *
     * The button posts to Shopware's *own* cart route, so this guardrail — which governs whether the
     * assistant's `add_to_cart` **tool** is ever constructed — does not technically apply to it. It
     * gates the button regardless. A merchant who switched off *"allow the assistant to add items to
     * the cart"* will not accept a plugin that still shows add buttons, and they would be right: the
     * setting is about the assistant, not about one code path inside it.
     */
    public function addToCartEnabled(string $salesChannelId): bool
    {
        return $this->assistantConfig->forSalesChannel($salesChannelId)->enableAddToCart;
    }

    /**
     * The `sessionStorage` slot name the widget's JavaScript stores its conversation token under —
     * see {@see ContextStorageKey} for what the 32 hex characters are and are not.
     *
     * **Degrades to `''` rather than propagating.** Every other function on this class is total: none
     * of them can throw, because a Twig function that throws turns one broken assumption into an
     * error page over the merchant's entire storefront, not just a missing widget feature.
     * {@see ShoppingContextResolver::current()} is the one exception available to this class — it
     * throws when called with no request-scoped sales-channel context, a state a real storefront
     * request should never be in, but "should never happen" is exactly the case a template-rendering
     * gate cannot afford to trust. An empty string is a slot name nothing will ever match, so the
     * widget's JavaScript simply starts without a stored token rather than crashing the page around
     * it — the same fail-open-to-nothing shape {@see self::isEnabled()} already uses for its own
     * unmet preconditions.
     */
    public function contextKey(): string
    {
        try {
            return $this->storageKey->for($this->shoppingContext->current());
        } catch (\RuntimeException) {
            return '';
        }
    }
}
