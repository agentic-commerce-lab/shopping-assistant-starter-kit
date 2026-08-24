import './swag-assistant-trace-detail.scss';
import template from './swag-assistant-trace-detail.html.twig';
import { humanMs, phaseFacts } from './facts';
import { alwaysVisible, prettyPayload, promptText, readTurns } from './payload';
import { buildTimeline, shopMs, splitTurns, waitMs } from './phases';

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

        dialogue() {
            return readTurns(this.conversation?.transcript);
        },

        /**
         * One entry per turn: the shopper's question, the assistant's reply, and the pipeline that
         * produced it, side by side. The transcript and the trace are two records of the same turns
         * and are zipped here — the transcript stores a user turn and an assistant turn per
         * exchange, so the assistant turns line up with the traces one to one.
         */
        turns() {
            const traces = splitTurns(Array.from(this.events ?? []));
            const spoken = this.dialogue;

            return traces.map((events, index) => {
                const rows = buildTimeline(events);
                const question = spoken[index * 2] ?? null;
                const answer = spoken[index * 2 + 1] ?? null;

                return {
                    index,
                    question,
                    answer,
                    rows,
                    shopMs: shopMs(rows),
                    waitMs: waitMs(rows),
                    totalMs: shopMs(rows) + waitMs(rows),
                    flags: rows.flatMap((row) => (row.type === 'phase' ? phaseFacts(row) : [])).filter((f) => f.alarming),
                };
            });
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            this.conversation = await this.conversationRepository.get(this.$route.params.id, Shopware.Context.api);

            const criteria = new Criteria(1, 500);
            criteria.addFilter(Criteria.equals('conversationId', this.$route.params.id));
            // `seq` is monotonic per conversation — the store offsets it each turn — so this is the
            // real execution order across every turn, not just within one.
            criteria.addSorting(Criteria.sort('seq', 'ASC'));

            this.events = await this.eventRepository.search(criteria, Shopware.Context.api);
            this.isLoading = false;
        },

        phaseLabel(row) {
            return this.$tc(`swag-assistant-trace.phase.${row.key}`);
        },

        facts(row) {
            return phaseFacts(row);
        },

        duration(ms) {
            return humanMs(ms);
        },

        /**
         * A row written before `elapsed_ms` existed reads 0, which is absence rather than an offset
         * of zero. Rendering "0 ms" there would be the always-zero column ruling R62 warned about.
         */
        offset(ms) {
            return ms ? `+${humanMs(ms)}` : '—';
        },

        /** Every event of a turn in order, for the raw disclosure. */
        rawEvents(turn) {
            return turn.rows.filter((row) => row.type === 'phase').flatMap((row) => row.events);
        },

        highlights(event) {
            return alwaysVisible(event.payload);
        },

        payloadJson(event) {
            return prettyPayload(event.payload);
        },

        /**
         * Non-null only for the `prompt` event. The template prefers it over `payloadJson`, because
         * JSON.stringify escapes this payload's newlines and turns four kilobytes of prose into one
         * line.
         */
        promptProse(event) {
            return promptText(event.payload);
        },
    },
});
