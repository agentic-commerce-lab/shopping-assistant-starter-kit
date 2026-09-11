import './component/swag-assistant-escalation-preview';
import './component/swag-assistant-status-switch';
import './component/swag-assistant-system-prompt';
import './module/swag-assistant-trace';
import './module/swag-assistant-shop-info';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

/*
 * The two settings components are not part of a module, so they have nowhere to declare snippets of
 * their own — `Shopware.Module.register()` is what carries the trace module's, and registering a
 * module here purely to hold strings would put an entry in the navigation for a card that already
 * lives in the plugin's own settings page.
 */
Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('de-DE', deDE);
