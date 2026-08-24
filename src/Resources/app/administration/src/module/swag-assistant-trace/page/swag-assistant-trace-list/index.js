import './swag-assistant-trace-list.scss';
import { exportFileName, exportRequest, saveBlob } from '../../export';
import { humanMs } from '../swag-assistant-trace-detail/facts';
import template from './swag-assistant-trace-list.html.twig';

const { Criteria } = Shopware.Data;

/**
 * `AssistantTraceExportController::MAX_CONVERSATIONS`, mirrored so the client asks for what the
 * server will accept rather than being refused after the round trip. **The two must stay equal.**
 */
const EXPORT_LIMIT = 1000;

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
            total: 0,
            selectionCount: 0,
            exportError: null,
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
                { property: 'customer.lastName', label: 'swag-assistant-trace.list.columnUser' },
                { property: 'turnCount', label: 'swag-assistant-trace.list.columnTurnCount' },
                // The column that makes this a triage tool rather than a log reader: with the
                // filter below, `tool_limit_exceeded` and `escalate` are reachable without reading
                // every row. Those are the two live failure modes.
                { property: 'outcome', label: 'swag-assistant-trace.list.columnOutcome' },
                { property: 'totalMs', label: 'swag-assistant-trace.list.columnTotalMs' },
            ];
        },

        /**
         * How many conversations the buttons would export right now.
         *
         * The selection when there is one, otherwise everything the filter matches. This is the one
         * place that rule lives — the label reads it, the disabled state reads it, and the handler
         * branches on the same thing, so the button cannot promise one count and send another.
         */
        exportCount() {
            return this.selectionCount || this.total;
        },

        /**
         * What the buttons would export, said in words beside them rather than inside them.
         *
         * `$t`, not `$tc`: there is no plural form here, and `$tc`'s second argument is the
         * pluralization choice — passing named values through it dropped them silently, which is
         * how the button once read "Export all 175 as" with nothing after the "as".
         */
        exportScope() {
            const key = this.selectionCount
                ? 'swag-assistant-trace.list.scopeSelected'
                : 'swag-assistant-trace.list.scopeAll';

            return this.$t(key, { count: this.exportCount });
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

        /**
         * Two states, and the second is not a fallback. A conversation with no customer either never
         * had one or had one who deleted their account — `ON DELETE SET NULL` makes those
         * indistinguishable on purpose, so "Guest user" is the only thing true in both cases.
         */
        userName(conversation) {
            const customer = conversation.customer;

            if (!customer) {
                return this.$tc('swag-assistant-trace.list.guestUser');
            }

            return `${customer.firstName ?? ''} ${customer.lastName ?? ''}`.trim();
        },

        async load() {
            this.isLoading = true;

            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            // Without this the result's `total` is 0 — the DAL does not count unless asked — and
            // the export button then offers "all 0", which is both wrong and disabled.
            criteria.setTotalCountMode(Criteria.TOTAL_COUNT_MODE_EXACT);
            // The name comes off the association rather than a second lookup: `customer_id` is a
            // real foreign key, unlike `sales_channel_id`.
            criteria.addAssociation('customer');

            if (this.outcomeFilter) {
                criteria.addFilter(Criteria.equals('outcome', this.outcomeFilter));
            }

            this.conversations = await this.repository.search(criteria, Shopware.Context.api);
            this.total = this.conversations.total ?? 0;
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

        onSelectionChange(selection) {
            this.selectionCount = Object.keys(selection ?? {}).length;
        },

        onExport(format) {
            if (this.selectionCount) {
                this.download(Object.keys(this.$refs.grid?.selection ?? {}), format);

                return;
            }

            this.exportFiltered(format);
        },

        /**
         * Every row the current filter matches, not just the page.
         *
         * Shopware's own select-all covers the current page, so a literal reading of "select all"
         * would quietly export 25 rows. `searchIds` fetches ids only, which is cheap even at the
         * bound the server enforces.
         */
        async exportFiltered(format) {
            const criteria = new Criteria(1, EXPORT_LIMIT);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            if (this.outcomeFilter) {
                criteria.addFilter(Criteria.equals('outcome', this.outcomeFilter));
            }

            const result = await this.repository.searchIds(criteria, Shopware.Context.api);

            this.download(result.data, format);
        },

        /**
         * The download goes through an authenticated request rather than `window.open`: the route
         * emits customer names and is ACL-protected, so it cannot be opened as a plain URL.
         */
        async download(ids, format) {
            this.exportError = null;

            const { url, options } = exportRequest(Shopware.Context.api, ids, format);
            const response = await fetch(url, options);

            if (!response.ok) {
                const problem = await response.json().catch(() => ({}));

                // Shown in the page rather than through a notification mixin: nothing in this module
                // uses one, and the endpoint's refusals are instructions about the filter — "narrow
                // it and try again" — which belong beside the filter they are about.
                this.exportError = problem.error ?? this.$tc('swag-assistant-trace.list.exportFailed');

                return;
            }

            saveBlob(await response.blob(), exportFileName(format));
        },
    },
});
