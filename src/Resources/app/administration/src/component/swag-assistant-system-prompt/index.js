import template from './swag-assistant-system-prompt.html.twig';
import './swag-assistant-system-prompt.scss';

/**
 * Shows the instructions the assistant is actually given, so the box above it stops being a
 * guess.
 *
 * The field above this one appends the merchant's own sentences to the end of those instructions.
 * Until now nothing in the form said what they were appended TO, so "your own instructions for the
 * assistant" asked a merchant to write into something they could not read.
 *
 * ## Collapsed, and loaded only when opened
 *
 * The prompt is roughly twelve thousand characters. Rendered open it would be the largest thing on
 * the settings page by an order of magnitude and would bury the settings it belongs to — so it is
 * one line until asked, and the request does not happen until the first expand. Nobody who does not
 * want it pays for it.
 *
 * ## Read-only, in a `<pre>` rather than a textarea
 *
 * Deliberate: a textarea would look editable, and it is not. The merchant's control is the one
 * appended slot, which cannot override the rules above it. An editable prompt would fork it — every
 * later improvement shipped with the plugin would either miss that shop or silently overwrite the
 * merchant's version, with no third outcome — so this exists to make the slot understandable, not to
 * widen it.
 *
 * ## Why it is fetched rather than templated
 *
 * `swag-assistant-escalation-preview` beside it is static on purpose. This cannot be: the prompt is
 * assembled from `enableEscalation`, `onlyGivenInformation`, `enableMatchReasons`,
 * `enableCompareProducts` and the reply language, so a copy pasted into this template would drift
 * from `SystemPrompt` at its next edit. A preview that lies is worse than none, because it is
 * exactly what a merchant reaches for to check what they changed.
 *
 * ## Two limits it states rather than hides
 *
 * It shows the **saved** configuration: `sw-system-config` does not pass its channel selection or
 * its unsaved values to a custom component — `getElementBind()` copies the element and nothing else
 * — and reaching into its internals for them is what the escalation preview's own docblock refuses.
 * So the channel is resolved here, from the shop's active Storefront channels, and named in the
 * output.
 *
 * And the per-conversation blocks are absent. The catalogue vocabulary and the "shopper is looking
 * at X" line are per-request probe results, so rendering them would need a sales-channel context and
 * a facet probe on an administration request, and would show one conversation rather than the
 * instructions.
 */
Shopware.Component.register('swag-assistant-system-prompt', {
    template,

    inject: ['httpClient', 'syncService', 'repositoryFactory'],

    data() {
        return {
            expanded: false,
            loading: false,
            error: null,
            prompt: '',
            characters: 0,
            channelName: '',
            channelCount: 0,
        };
    },

    computed: {
        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },
    },

    methods: {
        toggle() {
            this.expanded = !this.expanded;

            // Only ever loaded once, and only when someone asks: the payload is large and most
            // visits to this page have no reason to want it.
            if (this.expanded && this.prompt === '' && this.error === null) {
                this.load();
            }
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                const channel = await this.storefrontChannel();

                this.channelName = channel.name;

                const response = await this.httpClient.get(
                    `/_action/swag-assistant/system-prompt/${channel.id}`,
                    { headers: this.syncService.getBasicHeaders() },
                );

                this.prompt = response.data.prompt;
                this.characters = response.data.characters;
            } catch (error) {
                // Shown rather than swallowed: a blank panel would read as "there is no prompt",
                // which is the one thing this component must never suggest.
                this.error = error?.response?.data?.errors?.[0]?.detail ?? error?.message ?? 'unknown error';
            } finally {
                this.loading = false;
            }
        },

        /**
         * The shop's first active Storefront channel.
         *
         * `sw-system-config` does not tell a custom component which channel is selected, so this
         * picks one and says which it picked. `channelCount` drives the note about the others:
         * silently showing one of several channels' prompts as though it were the shop's would be a
         * preview that misleads on exactly the shops where per-channel overrides matter.
         */
        async storefrontChannel() {
            const criteria = new Shopware.Data.Criteria(1, 25);
            criteria.addFilter(Shopware.Data.Criteria.equals('active', true));
            criteria.addFilter(Shopware.Data.Criteria.equals('typeId', Shopware.Defaults.storefrontSalesChannelTypeId));

            const channels = await this.salesChannelRepository.search(criteria, Shopware.Context.api);

            this.channelCount = channels.total ?? channels.length;

            if (channels.length === 0) {
                throw new Error(this.$tc('swag-assistant-settings.systemPrompt.noStorefront'));
            }

            return channels[0];
        },
    },
});
