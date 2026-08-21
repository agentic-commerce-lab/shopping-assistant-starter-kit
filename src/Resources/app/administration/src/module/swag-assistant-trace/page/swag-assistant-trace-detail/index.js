import template from './swag-assistant-trace-detail.html.twig';
import { alwaysVisible, prettyPayload } from './payload';

const { Criteria } = Shopware.Data;

Shopware.Component.register('swag-assistant-trace-detail', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            conversation: null,
            events: null,
            isLoading: true,
        };
    },

    computed: {
        conversationRepository() {
            return this.repositoryFactory.create('swag_assistant_conversation');
        },

        eventRepository() {
            return this.repositoryFactory.create('swag_assistant_trace_event');
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            this.conversation = await this.conversationRepository.get(
                this.$route.params.id,
                Shopware.Context.api,
            );

            const criteria = new Criteria(1, 500);
            criteria.addFilter(Criteria.equals('conversationId', this.$route.params.id));
            // `seq` is monotonic per conversation — the store offsets it each turn — so this is
            // the real execution order across every turn, not just within one.
            criteria.addSorting(Criteria.sort('seq', 'ASC'));

            this.events = await this.eventRepository.search(criteria, Shopware.Context.api);
            this.isLoading = false;
        },

        /**
         * A row written before `elapsed_ms` existed reads 0, which is absence rather than an offset
         * of zero. Rendering "0ms" there would be the always-zero column ruling R62 warned about,
         * one layer up.
         */
        elapsedLabel(event) {
            if (!event.elapsedMs) {
                return this.$tc('swag-assistant-trace.detail.noOffset');
            }

            return `+${event.elapsedMs.toLocaleString()}ms`;
        },

        highlights(event) {
            return alwaysVisible(event.payload);
        },

        payloadJson(event) {
            return prettyPayload(event.payload);
        },
    },
});
