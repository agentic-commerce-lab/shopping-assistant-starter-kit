import './swag-assistant-insights-dashboard.scss';
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
 * Worst first.
 *
 * Sorted here rather than in the DAL because `severity` is a string column: `ORDER BY severity`
 * gives critical, info, warning — alphabetical, which puts the two that matter either side of the
 * one that does not. `injection_attempt` is always `info` by construction, so without this the
 * loudest-sounding findings sit at the top while a critical one sits below them.
 */
const SEVERITY_RANK = { critical: 0, warning: 1, info: 2 };

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
            // Null until the config answer arrives, so the page can withhold BOTH empty states for
            // one frame rather than flash "the feature is off" at a shop where it is on.
            insightsEnabled: null,
            isLoading: true,
        };
    },

    computed: {
        runRepository() {
            return this.repositoryFactory.create('swag_assistant_insight_run');
        },

        findingRepository() {
            return this.repositoryFactory.create('swag_assistant_insight_finding');
        },

        latestRun() {
            return this.runs[0] ?? null;
        },

        metrics() {
            return this.latestRun?.metrics ?? {};
        },

        /**
         * Which of the four states the top section is in.
         *
         * Four, not two, and the two the plan named are only half of it. `disabled` and
         * `nothingFound` mean opposite things — "it will never report" versus "it reported and the
         * news is good" — but so do the other two: `noRuns` is a shop switched on this afternoon
         * whose nightly task has not fired yet, and `judgeFailed` is a night whose counts are
         * intact and whose judge did not answer. Collapsing any pair of these tells a merchant
         * something false about their shop, and `judgeError` exists on the entity for exactly this
         * reason (see `InsightRunDefinition`).
         */
        state() {
            if (this.insightsEnabled === null) {
                return 'unknown';
            }

            if (!this.insightsEnabled) {
                return 'disabled';
            }

            if (!this.latestRun) {
                return 'noRuns';
            }

            if (this.latestRun.judgeError) {
                return 'judgeFailed';
            }

            return this.findings.length ? 'ready' : 'nothingFound';
        },

        /** Worst first, and stable within a severity so two reloads read the same. */
        worklist() {
            return [...this.findings].sort((left, right) => this.rank(left) - this.rank(right));
        },

        /**
         * The four charts, in the order the plan fixes them.
         *
         * The grouping is the whole design decision here: each chart holds series whose magnitudes
         * are comparable, because a line chart with 300 turns and 3 unsupported claims on one
         * y-axis draws the second series flat along the axis and hides it. So the funnel's three
         * counts sit together, the two search outcomes sit together, description coverage is its
         * own pair, and the three "the turn went wrong" counts share the fourth.
         *
         * `unsupportedClaims` rides with the aborted turns rather than with description coverage,
         * where it is causally at home: it is the deterministic counterpart of the judge's
         * `wrong_or_missed_answer`, it is small, and putting it beside `turnsWithDescription` would
         * have been the flat-line-along-the-axis mistake above.
         */
        charts() {
            return [
                { key: 'funnel', keys: ['conversations', 'cartAdded', 'checkoutOffered'] },
                { key: 'searches', keys: ['searchesEmpty', 'searchesOverCap'] },
                { key: 'descriptions', keys: ['turnsWithDescription', 'turnsWithoutDescription'] },
                { key: 'problems', keys: ['unsupportedClaims', 'abortedTurns', 'escalations'] },
            ].map((chart) => ({
                key: chart.key,
                options: lineOptions(this.$tc(`swag-assistant-insights.trends.${chart.key}`)),
                series: this.named(seriesFor(this.runs, chart.keys)),
            }));
        },

        /**
         * The technical half: the three counts that belong to whoever installed the plugin.
         *
         * `escalationsWithoutDestination` is the one that is a misconfiguration rather than a
         * measurement — a shopper asked for a human and there was nowhere to send them — so it
         * carries an `alarming` flag and the template colours it. The other two are facts about
         * the model and the shop, loud only in the trend above.
         */
        technical() {
            return [
                { key: 'abortedTurns', alarming: false },
                { key: 'escalations', alarming: false },
                {
                    key: 'escalationsWithoutDestination',
                    alarming: (this.metrics.escalationsWithoutDestination ?? 0) > 0,
                },
            ];
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
         */
        async loadConfig() {
            const config = await this.systemConfigApiService.getValues('SwagAssistantStarterKit.config', null);

            this.insightsEnabled = isTrue(config['SwagAssistantStarterKit.config.insightsEnabled']);
        },

        async loadRuns() {
            const criteria = new Criteria(1, TREND_RUNS);
            // `windowEnd`, not `createdAt`: a run written by a replay or a catch-up covers a window
            // that is not the night it was written in, and the charts plot the window.
            criteria.addSorting(Criteria.sort('windowEnd', 'DESC'));

            this.runs = Array.from(await this.runRepository.search(criteria, Shopware.Context.api));
        },

        /**
         * The newest run's findings, and only that run's.
         *
         * Not an association on the runs query: thirty nights of findings is thirty times the rows
         * and the quotes are the largest columns in the plugin, all to render one night. The
         * charts need counts, this needs prose, and they are two requests for that reason.
         */
        async loadFindings() {
            if (!this.latestRun) {
                this.findings = [];

                return;
            }

            const criteria = new Criteria(1, FINDING_LIMIT);
            criteria.addFilter(Criteria.equals('runId', this.latestRun.id));

            this.findings = Array.from(await this.findingRepository.search(criteria, Shopware.Context.api));
        },

        rank(finding) {
            return SEVERITY_RANK[finding.severity] ?? SEVERITY_RANK.info;
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

        /**
         * Severity to an `sw-label` variant.
         *
         * `danger`, not `error`: `sw-label` validates against info, danger, success, warning,
         * neutral, neutral-reversed and primary. An unknown variant is not rejected — the class
         * simply never matches, so the label renders grey and the severity is silently gone, the
         * same failure the trace view hit with `sw-alert variant="error"`.
         *
         * A severity the closed set does not cover falls to `neutral` rather than to `danger`:
         * guessing loud on an unknown value is how a dashboard cries wolf.
         */
        severityVariant(severity) {
            return { critical: 'danger', warning: 'warning', info: 'info' }[severity] ?? 'neutral';
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
    },
});
