import { humanMs } from '../swag-assistant-trace-detail/facts';
import template from './swag-assistant-trace-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('swag-assistant-trace-list', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            conversations: null,
            channelNames: {},
            isLoading: true,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            outcomeFilter: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('swag_assistant_conversation');
        },

        columns() {
            return [
                {
                    property: 'createdAt',
                    label: 'swag-assistant-trace.list.columnCreatedAt',
                    primary: true,
                    allowResize: true,
                },
                { property: 'salesChannelId', label: 'swag-assistant-trace.list.columnSalesChannel' },
                { property: 'turnCount', label: 'swag-assistant-trace.list.columnTurnCount' },
                // The column that makes this a triage tool rather than a log reader: with the
                // filter below, `tool_limit_exceeded` and `escalate` are reachable without reading
                // every row. Those are the two live failure modes.
                { property: 'outcome', label: 'swag-assistant-trace.list.columnOutcome' },
                { property: 'totalMs', label: 'swag-assistant-trace.list.columnTotalMs' },
            ];
        },

        outcomeOptions() {
            return [
                { value: 'product_shown', label: 'product_shown' },
                { value: 'tool_limit_exceeded', label: 'tool_limit_exceeded' },
                { value: 'escalate', label: 'escalate' },
                { value: 'refused', label: 'refused' },
            ];
        },
    },

    created() {
        this.loadChannelNames();
        this.load();
    },

    methods: {
        /**
         * Resolved client-side rather than through an association: `sales_channel_id` is a plain
         * VARCHAR(32) of hex, not a BINARY(16) foreign key, so the DAL cannot join it. Showing a
         * raw uuid to a merchant is not an option, and changing the column type is a migration
         * this page does not justify.
         */
        async loadChannelNames() {
            const channels = await this.repositoryFactory
                .create('sales_channel')
                .search(new Criteria(1, 100), Shopware.Context.api);

            this.channelNames = Object.fromEntries(channels.map((channel) => [channel.id, channel.name]));
        },

        channelName(id) {
            return this.channelNames[id] ?? id;
        },

        async load() {
            this.isLoading = true;

            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            if (this.outcomeFilter) {
                criteria.addFilter(Criteria.equals('outcome', this.outcomeFilter));
            }

            this.conversations = await this.repository.search(criteria, Shopware.Context.api);
            this.isLoading = false;
        },

        /** `8183` reads as an id. A duration column has to read as a duration. */
        duration(ms) {
            return ms ? humanMs(ms) : '—';
        },

        onOutcomeFilterChange(value) {
            this.outcomeFilter = value;
            this.load();
        },
    },
});
