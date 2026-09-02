<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The storefront-widget fields from `config.xml`.
 *
 * Separate from {@see SystemConfigAssistantConfig} on purpose: those fields are policy the model is
 * bound by, and these are presentation. A merchant hiding the widget has not changed what the
 * assistant may do — the chat endpoint stays reachable, so a custom interface built against it keeps
 * working. Collapsing the two would make that distinction unstateable.
 *
 * `widgetEnabled` is read through the raw `get()` for the same reason `enableAddToCart` is: its
 * default is **on**, and `getBool()` returns `false` for an absent key and for a stored `false`
 * alike. A shop that never opened the config form must get the documented default rather than a
 * silently hidden widget.
 */
final readonly class SystemConfigWidgetSettings
{
    /**
     * Only a last-resort fallback. The shopper-facing default lives in the `swagAssistant.panel`
     * snippets, where it can be translated; this exists so the panel always has an accessible name
     * even if a template forgets to supply one.
     */
    private const DEFAULT_NAME = 'Shopping Assistant';

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    public function isWidgetEnabled(string $salesChannelId): bool
    {
        $value = $this->systemConfig->get(SystemConfigAssistantConfig::PREFIX . 'widgetEnabled', $salesChannelId);

        if ($value === null) {
            return true;
        }

        // Same reasoning as SystemConfigAssistantConfig::boolOr(): the CLI stores booleans as
        // strings, and `(bool) "false"` is `true`. A plain cast here would make `widgetEnabled false`
        // set from the console keep showing the widget.
        return filter_var($value, \FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The merchant's appearance choices, validated.
     *
     * Returns a value object rather than three strings because the foreground is *derived* from the
     * primary and must not be able to travel separately from it — see {@see WidgetTheme::readableOn()}.
     */
    public function theme(string $salesChannelId): WidgetTheme
    {
        $prefix = SystemConfigAssistantConfig::PREFIX;

        return WidgetTheme::of(
            $this->systemConfig->getString($prefix . 'primaryColor', $salesChannelId),
            $this->systemConfig->getString($prefix . 'secondaryColor', $salesChannelId),
            $this->systemConfig->getString($prefix . 'entryPointStyle', $salesChannelId),
        );
    }

    public function assistantName(string $salesChannelId): string
    {
        $name = trim($this->systemConfig->getString(
            SystemConfigAssistantConfig::PREFIX . 'assistantName',
            $salesChannelId,
        ));

        return $name === '' ? self::DEFAULT_NAME : $name;
    }
}
