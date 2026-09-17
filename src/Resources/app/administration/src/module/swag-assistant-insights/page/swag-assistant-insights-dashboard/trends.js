/**
 * Turns run rows into `sw-chart` series.
 *
 * A run's `metrics` is a flat map of counts, so a chart is one key over time. Kept out of the page
 * component because the page is markup and this is arithmetic — and because it is the only part of
 * the view worth asserting in a test.
 *
 * **The points are `{x, y}` objects, not `[timestamp, value]` tuples.** The plan specified tuples,
 * and ApexCharts accepts them — but `sw-chart` does not pass the series straight through. Its
 * `mergedOptions` always injects `labels: this.series.flatMap((s) => s.data.map((d) => d.x))`, and
 * its `sortSeries`/`addZeroValuesToSeries` helpers read `d.x` too. Fed tuples, that produces an
 * array of `undefined` labels and makes the `sort` and `fillEmptyValues` props silently useless.
 * Read out of the installed component (`app/component/base/sw-chart/index.js`, 6.7), not guessed.
 * The tuple form would have worked today and broken the first time anyone added `:sort="true"`.
 */
export function seriesFor(runs, metricKeys) {
    return metricKeys.map((key) => ({
        name: key,
        data: runs
            .map((run) => ({ x: new Date(run.windowEnd).getTime(), y: run.metrics?.[key] ?? 0 }))
            .sort((a, b) => a.x - b.x),
    }));
}

/**
 * One colour per series, as literal hex.
 *
 * **Not `var(--color-…)`, although `sw-chart`'s own defaults use custom properties.** ApexCharts
 * runs its own shade/lighten arithmetic over `colors` for hover and fill states, and a `var()`
 * string is not a colour it can compute on — it produces `NaN` components and silently draws grey.
 * A stroke colour survives being a `var()` because it goes straight onto an SVG attribute; a series
 * colour does not, so the two cannot be written the same way.
 *
 * Also assigned to `stroke.colors`, and that is the whole reason this constant exists.
 * `sw-chart.defaultOptions` sets `stroke.colors: ['var(--color-border-brand-default)']` — ONE
 * colour, for every series — and `mergedOptions` deep-merges, so a chart that only sets width and
 * curve keeps it. Measured in a browser: all three lines of the funnel chart drew in the same blue
 * and only the dots differed, which makes a three-series line chart unreadable.
 *
 * `dashArray` carries the same distinction without colour, for a merchant who cannot separate the
 * green from the amber and for the screenshot that ends up in a black-and-white print-out.
 *
 * **Deliberately NOT also passed to `tooltip.marker.fillColors`.** The hover tooltip's dots were
 * all brand blue, and it looks like the same class of bug — but it is not an options problem at
 * all: ApexCharts already writes the correct per-series `color` on each tooltip marker, and the
 * Administration's own stylesheet overrides the dot with `color: … !important`. No value passed
 * here can beat that, and passing this list again would add a second copy of the palette that can
 * drift from the first while fixing nothing. The fix is the CSS override in this page's SCSS, which
 * restores `currentcolor` and so keeps ONE source of truth: this array.
 *
 * `defaultOptions` was read end to end for other single-colour values a multi-series chart needs a
 * list for. `stroke.colors` is the only one. `title.style.color`, `xaxis`/`yaxis`
 * `labels.style.colors`, `axisBorder.color`, `axisTicks.color`, `crosshairs.stroke.color` and
 * `grid.borderColor` are all correctly one colour — they paint chart furniture, not series — and
 * `markers` carries only a size, so the point markers take their colour from `colors` above and
 * were already right. There are no `dataLabels` on a line chart by default, and none render here.
 */
const SERIES_COLOURS = ['#0870ff', '#16c39a', '#ffab22'];

/**
 * The options every chart on this page shares, plus its title.
 *
 * Here rather than in the page component because it is the other half of `seriesFor()`: the series
 * carry epoch milliseconds, so `xaxis.type` MUST be `datetime` or ApexCharts renders 1.78e12 as a
 * category label. Keeping the two together is what stops that pairing being broken by editing one
 * of them.
 *
 * `yaxis.min: 0` and the integer formatter are both about counts: these are conversations and
 * turns, so a y-axis that auto-scales to 3.5 or dips below zero is showing a quantity that cannot
 * exist. `tickAmount` is left to ApexCharts — pinning it makes a chart of 0, 1 and 2 conversations
 * repeat the same label three times.
 */
export function lineOptions(title) {
    return {
        chart: { toolbar: { show: false }, zoom: { enabled: false } },
        title: { text: title, style: { fontSize: '14px' } },
        colors: SERIES_COLOURS,
        stroke: { width: 2, curve: 'straight', colors: SERIES_COLOURS, dashArray: [0, 5, 2] },
        // ApexCharts derives a line's stroke opacity from `fill.opacity`, whose default for a line
        // chart is 0.85 — so every stroke rendered as `rgba(…, 0.85)` and two series crossing each
        // other produced a muddy grey segment at the crossing. Read off the rendered `<path>`
        // attributes in a browser, not from the docs.
        fill: { opacity: 1 },
        // One marker per run, because a shop with three runs is three points and a line between
        // three points with no markers reads as two segments of nothing.
        markers: { size: 4 },
        xaxis: { type: 'datetime' },
        yaxis: {
            min: 0,
            forceNiceScale: true,
            labels: { formatter: (value) => `${Math.round(value)}` },
        },
        legend: { show: true, position: 'bottom' },
    };
}
