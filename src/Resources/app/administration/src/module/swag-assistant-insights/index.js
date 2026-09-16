import './acl';
import './page/swag-assistant-insights-dashboard';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

/**
 * Its own module rather than a third tab on the trace view.
 *
 * The trace view answers "what happened in this one conversation" and is opened because something
 * is already known to be wrong. This answers "what went wrong last night that nobody has noticed
 * yet" — a merchant reaches for it without a conversation in mind, and from here into a trace
 * rather than the other way round. Same accent as the other two modules, because a third colour in
 * the settings list would imply a third product.
 *
 * The single route is `dashboard`, not `index`: `module.factory` names a route
 * `<module id with dots>.<route key>`, so this produces `swag.assistant.insights.dashboard`, which
 * is the name the plan and the navigation entry below both use. There is deliberately no list route
 * — a run row on its own says nothing a merchant can act on, and the page already carries the last
 * thirty of them as charts.
 */
Shopware.Module.register('swag-assistant-insights', {
    type: 'plugin',
    name: 'swag-assistant-insights',
    title: 'swag-assistant-insights.general.mainMenuItemGeneral',
    description: 'swag-assistant-insights.general.description',
    color: '#57D9A3',
    icon: 'regular-chart-line',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        dashboard: {
            component: 'swag-assistant-insights-dashboard',
            path: 'dashboard',
            // The insights' own right, not the conversation right: a run carries only counts, and a
            // shop may want merchandising staff reading the numbers while raw conversations stay
            // with whoever administers the plugin. See `acl/index.js` for why the conversation
            // right is a declared dependency rather than a bundled privilege.
            meta: { privilege: 'swag_assistant_insight.viewer' },
        },
    },

    navigation: [{
        label: 'swag-assistant-insights.general.mainMenuItemGeneral',
        color: '#57D9A3',
        path: 'swag.assistant.insights.dashboard',
        icon: 'regular-chart-line',
        parent: 'sw-settings',
        position: 120,
    }],
});
