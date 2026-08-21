import template from './swag-assistant-trace-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('swag-assistant-trace-list', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            conversations: null,
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
        this.load();
    },

    methods: {
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

        onOutcomeFilterChange(value) {
            this.outcomeFilter = value;
            this.load();
        },
    },
});
