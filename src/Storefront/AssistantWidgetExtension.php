<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Storefront;

use Swag\AssistantStarterKit\Core\Config\SystemConfigAssistantConfig;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Config\SystemConfigWidgetSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Everything the widget's templates need to know, and nothing else.
 *
 * ## Why the gate lives in PHP
 *
 * `isConfigured()` already encodes what *"this shop can answer"* means — all three credentials
 * present, environment overriding stored config — and `killSwitch` already encodes *"stop
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

        return !$this->assistantConfig->forSalesChannel($salesChannelId)->killSwitch;
    }

    public function assistantName(string $salesChannelId): string
    {
        return $this->widgetSettings->assistantName($salesChannelId);
    }

    public function greeting(string $salesChannelId): string
    {
        return $this->widgetSettings->greeting($salesChannelId);
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
}
