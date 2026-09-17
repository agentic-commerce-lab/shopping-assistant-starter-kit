/**
 * How a finding is ordered and coloured.
 *
 * Split out of the page component when the search terms arrived and pushed it past the project's
 * 400-line norm. The seam is the honest one rather than the convenient one: `runs.js` answers
 * "which run, and what state is it in", this answers "how is one finding presented", and the page
 * component is left holding the requests and the markup.
 */

/**
 * Worst first.
 *
 * Ranked here rather than in the DAL because `severity` is a string column: `ORDER BY severity`
 * gives critical, info, warning — alphabetical, which puts the two that matter either side of the
 * one that does not. `injection_attempt` is always `info` by construction, so without this the
 * loudest-sounding findings sit at the top while a critical one sits below them.
 */
const SEVERITY_RANK = { critical: 0, warning: 1, info: 2 };

/**
 * Worst first, and stable within a severity so two reloads read the same.
 *
 * Copies before sorting: `Array.prototype.sort` mutates, and the array handed in is the component's
 * reactive `findings`, which would reorder under the template mid-render.
 */
export function worklistOrder(findings) {
    const rank = (finding) => SEVERITY_RANK[finding.severity] ?? SEVERITY_RANK.info;

    return [...findings].sort((left, right) => rank(left) - rank(right));
}

/**
 * Severity to an `sw-label` variant.
 *
 * `danger`, not `error`: `sw-label` validates against info, danger, success, warning, neutral,
 * neutral-reversed and primary. An unknown variant is not rejected — the class simply never
 * matches, so the label renders grey and the severity is silently gone, the same failure the trace
 * view hit with `sw-alert variant="error"`.
 *
 * A severity the closed set does not cover falls to `neutral` rather than to `danger`: guessing
 * loud on an unknown value is how a dashboard cries wolf.
 */
export function severityVariant(severity) {
    return { critical: 'danger', warning: 'warning', info: 'info' }[severity] ?? 'neutral';
}
