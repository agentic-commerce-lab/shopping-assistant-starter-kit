import './acl';
import './page/swag-assistant-trace-detail';
import './page/swag-assistant-trace-list';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Module.register('swag-assistant-trace', {
    type: 'plugin',
    name: 'swag-assistant-trace',
    title: 'swag-assistant-trace.general.mainMenuItemGeneral',
    description: 'swag-assistant-trace.general.description',
    color: '#57D9A3',
    icon: 'regular-comments',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        index: {
            component: 'swag-assistant-trace-list',
            path: 'index',
            meta: { privilege: 'swag_assistant_conversation.viewer' },
        },
        detail: {
            component: 'swag-assistant-trace-detail',
            path: 'detail/:id',
            meta: {
                privilege: 'swag_assistant_conversation.viewer',
                parentPath: 'swag.assistant.trace.index',
            },
        },
    },

    navigation: [{
        label: 'swag-assistant-trace.general.mainMenuItemGeneral',
        color: '#57D9A3',
        path: 'swag.assistant.trace.index',
        icon: 'regular-comments',
        parent: 'sw-settings',
        position: 100,
    }],
});
