/*
 * Storefront entry point. Shopware auto-detects this file.
 *
 * Registrations only, and every one lazy — the shape SwagPayPal uses. The orb chunk is tiny and
 * loads on every page; the panel chunk (rendering, transport, the thinking choreography) downloads
 * only when a shopper first opens the assistant. That split is why the widget costs a storefront
 * page essentially nothing.
 */
const PluginManager = window.PluginManager;

PluginManager.register(
    'SwagAssistantOrb',
    () => import('./assistant/orb.plugin'),
    '[data-swag-assistant-orb]',
);

PluginManager.register(
    'SwagAssistantPanel',
    () => import('./assistant/panel.plugin'),
    '[data-swag-assistant-root]',
);
