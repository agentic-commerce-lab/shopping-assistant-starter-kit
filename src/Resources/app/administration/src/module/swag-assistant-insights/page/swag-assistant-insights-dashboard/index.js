import './swag-assistant-insights-dashboard.scss';
import { CHART_GROUPS, technicalRows } from './sections';
import { MAX_TERMS, RETENTION_FALLBACK_DAYS, runState, searchTermLists as termListsFor } from './runs';
import { severityVariant as variantFor, worklistOrder } from './findings';
import { lineOptions, seriesFor } from './trends';
import template from './swag-assistant-insights-dashboard.html.twig';

const { Criteria } = Shopware.Data;

/**
 * How many runs the trends cover (spec 5.5).
 *
 * Thirty nights is a month, which is the shortest window in which a seasonal shop's numbers mean
 * anything, and it is also cheap: one request, thirty rows of counts, no associations.
 */
const TREND_RUNS = 30;

/**
 * The bound on one night's findings.
 *
 * A night that produced more than this has a systemic problem, not a worklist — and the sample
 * percentage is the control for that, not a paginator here. Deliberately generous rather than a
 * page size: a merchant reads this list top to bottom once, and a "next page" on a worklist is how
 * the bottom half never gets read.
 */
const FINDING_LIMIT = 100;

/**
 * A stored boolean, read the way the server reads it.
 *
 * The config API returns `true` for a value written through the Administration and the STRING
 * `"true"` for one written by `system:config:set`, and `(Boolean) "false"` is `true` — the same trap
 * `StoredValueReader::bool()` exists for on the PHP side. A bare cast here would report a
 * switched-off feature as on, which is exactly the empty state this page must not get wrong.
 */
function isTrue(value) {
    return value === true || value === 'true' || value === 1 || value === '1';
}

Shopware.Component.register('swag-assistant-insights-dashboard', {
    template,

    inject: ['repositoryFactory', 'systemConfigApiService'],

    data() {
        return {
            runs: [],
            findings: [],
            // Null until a run is picked, which means "the newest one". Not initialised to the
            // newest id because the runs have not arrived yet when `data()` runs, and a watcher
            // that filled it in later would fire a second findings request for the run the first
            // one already fetched.
            selectedRunId: null,
            // Null until the config answer arrives, so the page can withhold the "switched off"
            // banner for one frame rather than flash it at a shop where the feature is on.
            insightsEnabled: null,
            retentionDays: RETENTION_FALLBACK_DAYS,
            isLoading: true,
            isLoadingFindings: false,
        };
    },

    computed: {
        runRepository() {
            return this.repositoryFactory.create('swag_assistant_insight_run');
        },

        findingRepository() {
            return this.repositoryFactory.create('swag_assistant_insight_finding');
        },

        /**
         * The run the page is showing, which is the newest until a merchant picks another.
         *
         * Falls back to the newest rather than to null when the id is unknown: a run deleted
         * between the list arriving and the select firing would otherwise blank the section, and
         * the newest run is never the wrong thing to show.
         */
        selectedRun() {
            return this.runs.find((run) => run.id === this.selectedRunId) ?? this.runs[0] ?? null;
        },

        showingNewestRun() {
            return this.selectedRun !== null && this.selectedRun === this.runs[0];
        },

        /**
         * Every stored run, newest first, labelled by its window.
         *
         * **Labelled by date, never by id.** A merchant thinks "what happened on the 3rd", and a
         * 32-character hex id answers no question anyone has. The runs arrive sorted by `windowEnd`
         * DESC, so this preserves that order rather than re-sorting.
         */
        runOptions() {
            return this.runs.map((run) => ({ value: run.id, label: this.runLabel(run) }));
        },

        /**
         * The selected run's counts, so the whole page speaks about one run.
         *
         * The technical section below reads this too. It used to read the newest run unconditionally
         * — which, once a merchant can pick an older run, would have put September's findings above
         * last night's aborted-turn counts with nothing on the page saying so. The window is printed
         * under that heading for the same reason.
         */
        metrics() {
            return this.selectedRun?.metrics ?? {};
        },

        /**
         * The findings list's state for the selected run. Five states — see `runs.js` for why none
         * of them can be folded into another.
         *
         * Deliberately NOT a state of the feature. "Switched off" is a fact about the setting and is
         * shown as a banner of its own, because switching the nightly run off must not hide the
         * thirty days of findings already stored — that is the whole point of the picker above.
         */
        state() {
            return runState({
                run: this.selectedRun,
                findingCount: this.findings.length,
                retentionDays: this.retentionDays,
                now: new Date(),
                isLoading: this.isLoadingFindings,
            });
        },

        /**
         * Whether to say the feature is off.
         *
         * `=== false`, not `!`: `null` means the config answer has not arrived (or could not be
         * read at all, see `loadConfig()`), and "we do not know" must never render as "it is off".
         */
        isSwitchedOff() {
            return this.insightsEnabled === false;
        },

        /** Worst first, and stable within a severity so two reloads read the same. */
        worklist() {
            return worklistOrder(this.findings);
        },

        /**
         * The two search-term lists for the selected run, dropped when there is nothing to explain.
         *
         * A list survives into the template when its COUNT is non-zero — not when it has words. A
         * run with three turns that found nothing and no recorded terms still has something true to
         * say; a run where nothing went wrong has no heading to earn.
         */
        searchTermLists() {
            return termListsFor(this.selectedRun).filter((list) => list.count > 0);
        },

        charts() {
            return CHART_GROUPS.map((chart) => ({
                key: chart.key,
                options: lineOptions(this.$tc(`swag-assistant-insights.trends.${chart.key}`)),
                series: this.named(seriesFor(this.runs, chart.keys)),
            }));
        },

        technical() {
            return technicalRows(this.metrics);
        },
    },

    created() {
        this.load();
    },

    methods: {
        async load() {
            this.isLoading = true;

            // Sequential, not parallel: the findings query needs the newest run's id, and the
            // config read is what decides whether either answer means anything.
            await this.loadConfig();
            await this.loadRuns();
            await this.loadFindings();

            this.isLoading = false;
        },

        /**
         * **Global scope only, unlike the shop-info page's two-scope merge.**
         *
         * The nightly run is shop-wide in v1 (spec: "the window and the sample are shop-wide") —
         * there is no sales-channel selector on this page because there is nothing per channel to
         * select. Reading a channel override here would let a shop that switched insights on for
         * one storefront see the dashboard report itself as off, or the reverse, for a feature that
         * never consulted the channel value in the first place.
         *
         * `traceRetentionDays` is read from the same answer because it is the boundary between "the
         * judge found nothing" and "retention deleted the quotes" — see `runs.js`, including which
         * part of that boundary this global read gets wrong.
         *
         * **A refusal leaves `insightsEnabled` null rather than false.** Neither this module's ACL
         * nor the shop-info module's grants `system_config:read`, so a viewer holding only
         * `swag_assistant_insight.viewer` gets a 403 here — and an unhandled rejection used to
         * leave the whole section rendering nothing at all, because `null` withholds the banner by
         * design. Swallowing it keeps the findings and the charts, which is the half of the page
         * that viewer came for; claiming the feature is off on the strength of a permission error
         * would be inventing a fact about the shop.
         */
        async loadConfig() {
            try {
                const config = await this.systemConfigApiService.getValues('SwagAssistantStarterKit.config', null);

                this.insightsEnabled = isTrue(config['SwagAssistantStarterKit.config.insightsEnabled']);
                this.retentionDays = Number(config['SwagAssistantStarterKit.config.traceRetentionDays'])
                    || RETENTION_FALLBACK_DAYS;
            } catch (refusal) {
                this.insightsEnabled = null;
                this.retentionDays = RETENTION_FALLBACK_DAYS;
            }
        },

        async loadRuns() {
            const criteria = new Criteria(1, TREND_RUNS);
            // `windowEnd`, not `createdAt`: a run written by a replay or a catch-up covers a window
            // that is not the night it was written in, and the charts plot the window.
            criteria.addSorting(Criteria.sort('windowEnd', 'DESC'));

            this.runs = Array.from(await this.runRepository.search(criteria, Shopware.Context.api));
        },

        /**
         * One run's findings, and only that run's.
         *
         * Not an association on the runs query: thirty nights of findings is thirty times the rows
         * and the quotes are the largest columns in the plugin, all to render one night. The
         * charts need counts, this needs prose, and they are two requests for that reason — which
         * is also what makes the picker cheap, since changing runs re-runs only this one.
         */
        async loadFindings() {
            if (!this.selectedRun) {
                this.findings = [];

                return;
            }

            const criteria = new Criteria(1, FINDING_LIMIT);
            criteria.addFilter(Criteria.equals('runId', this.selectedRun.id));

            this.findings = Array.from(await this.findingRepository.search(criteria, Shopware.Context.api));
        },

        /**
         * A merchant picked another run.
         *
         * `isLoadingFindings` rather than the page-wide `isLoading`: reloading the whole page would
         * tear down four charts that did not change, and `state()` reads this flag FIRST so the
         * list cannot flash "nothing to report" on its way to four findings. The old findings are
         * dropped before the request, so a slow answer cannot leave the previous run's quotes
         * sitting under the new run's date.
         */
        async onRunChange(runId) {
            this.selectedRunId = runId;
            this.findings = [];
            this.isLoadingFindings = true;

            try {
                await this.loadFindings();
            } finally {
                this.isLoadingFindings = false;
            }
        },

        /**
         * Metric keys are internal names; a legend is read by a merchant.
         *
         * Translated here rather than inside `seriesFor()` so that file stays free of `$tc` and
         * testable without a Vue instance — the reason the plan put it in its own module.
         */
        named(series) {
            return series.map((serie) => ({
                ...serie,
                name: this.$tc(`swag-assistant-insights.metric.${serie.name}`),
            }));
        },

        metricLabel(key) {
            return this.$tc(`swag-assistant-insights.metric.${key}`);
        },

        /** Zero is a measurement and renders as `0`; a metric the run never wrote renders as `—`. */
        metricValue(key) {
            return this.metrics[key] ?? '—';
        },

        typeLabel(type) {
            return this.$tc(`swag-assistant-insights.type.${type}`);
        },

        severityLabel(severity) {
            return this.$tc(`swag-assistant-insights.severity.${severity ?? 'info'}`);
        },

        severityVariant(severity) {
            return variantFor(severity);
        },

        /**
         * The window the run covered, as one sentence.
         *
         * `$t`, not `$tc`: `$tc`'s second argument is the pluralization choice, and passing named
         * values through it drops them silently — the trace list shipped a label reading
         * "Export all 175 as" with nothing after the "as" for exactly this reason.
         */
        windowLabel(run) {
            return this.$t('swag-assistant-insights.lastNight.window', {
                start: Shopware.Utils.format.date(run.windowStart),
                end: Shopware.Utils.format.date(run.windowEnd),
            });
        },

        /**
         * One run, as one date, for the picker.
         *
         * **Not `windowLabel()`, and that is a defect found by looking at it.** The full window
         * reads "Covering 15 September 2026 at 09:00 to 16 September 2026 at 09:00" — 62 characters
         * — and `sw-single-select` does not clip its selected label: it wrapped out of the control,
         * under the chevron, in the first browser it was opened in. Widening the select was the
         * wrong fix; the range is redundant in a list where every row is one night, and the card's
         * subtitle already prints the window in full once a run is selected.
         *
         * The END of the window, not the start: a night's report is the one a merchant reads that
         * morning, and "16 September" is how they refer to it. The time stays because a replay can
         * write a second run ending on the same day, and two identical options in a dropdown is a
         * choice nobody can make.
         */
        runLabel(run) {
            return Shopware.Utils.format.date(run.windowEnd, {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                hour: 'numeric',
                minute: 'numeric',
            });
        },

        /**
         * That a term list is only the first {@see MAX_TERMS} of them.
         *
         * `$t`, not `$tc`, for the same reason as every other interpolated string on this page:
         * `$tc`'s second argument is the pluralization choice and silently drops named values.
         */
        cappedNotice() {
            return this.$t('swag-assistant-insights.terms.capped', { count: MAX_TERMS });
        },

        /**
         * Why an old run's findings list is empty, with the number that decided it.
         *
         * The retention window is named rather than described, because "older than your retention
         * window" invites the question this sentence exists to answer and the merchant would have
         * to go and look it up in another card.
         */
        prunedNotice() {
            return this.$t('swag-assistant-insights.empty.pruned', { days: this.retentionDays });
        },
    },
});
