import './acl';
import './page/swag-assistant-shop-info-list';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

/**
 * Its own settings module rather than a tab inside the trace view.
 *
 * The trace view answers "what happened in this conversation"; this answers "what can the assistant
 * read". A merchant reaches for them for unrelated reasons, and one is read-only history while the
 * other is the only write surface the plugin has.
 */
Shopware.Module.register('swag-assistant-shop-info', {
    type: 'plugin',
    name: 'swag-assistant-shop-info',
    title: 'swag-assistant-shop-info.general.mainMenuItemGeneral',
    description: 'swag-assistant-shop-info.general.description',
    // Deliberately the same accent as the trace module: these are two views of one plugin, and a
    // second colour would imply a second product in the settings list.
    color: '#57D9A3',
    icon: 'regular-file-text',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'swag-assistant-shop-info-list',
            path: 'index',
            meta: { privilege: 'swag_assistant_document.viewer' },
        },
    },

    navigation: [{
        label: 'swag-assistant-shop-info.general.mainMenuItemGeneral',
        color: '#57D9A3',
        path: 'swag.assistant.shop.info.index',
        icon: 'regular-file-text',
        parent: 'sw-settings',
        position: 110,
    }],
});
