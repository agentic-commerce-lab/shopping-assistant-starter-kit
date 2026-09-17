/**
 * Which of a run's five states the findings list is in, and the retention arithmetic behind one of
 * them.
 *
 * Its own module for the same reason as `trends.js`: the page is markup, this is a decision. Kept
 * out of the component because "did retention delete this run's findings, or did the judge find
 * nothing" is the one piece of logic on this page that can be wrong in a way nobody would notice —
 * both look like an empty list.
 */

/**
 * `TraceRetentionSettings::DEFAULT_DAYS`, mirrored.
 *
 * The plugin prunes on 30 days when the setting is blank or 0 — there is deliberately no "keep
 * forever" — and the config API returns nothing at all for a value never written. Reading the
 * absent key as 0 would put the cutoff at today and report every run's findings as pruned,
 * including last night's. **The two must stay equal.**
 */
export const RETENTION_FALLBACK_DAYS = 30;

/**
 * The instant before which a conversation no longer exists, and therefore nor do the findings that
 * quoted it.
 */
export function retentionCutoff(retentionDays, now) {
    const days = retentionDays > 0 ? retentionDays : RETENTION_FALLBACK_DAYS;

    return now.getTime() - days * 24 * 60 * 60 * 1000;
}

/**
 * Whether this run's findings are gone by design rather than never written.
 *
 * **A heuristic, and the only one available.** A finding is deleted as an orphan of its
 * conversation (`conversation_id` is `ON DELETE SET NULL`, and `InsightRetentionPruner` sweeps the
 * orphans), so nothing in the row records how many findings a run once had. Zero findings on an old
 * run and zero findings on a clean old run are identical in the database; the window's age is the
 * only thing left to read.
 *
 * It errs on the side of saying the evidence was deleted, which is the safe direction: telling a
 * merchant "nothing to report" about a night whose quotes retention removed is a claim about their
 * shop that is not true, while "these were deleted" about a genuinely clean old night costs them
 * nothing.
 *
 * **Known imprecision, not acted on.** `traceRetentionDays` is settable per sales channel and the
 * pruner honours each channel's own window, so a shop with one channel on 7 days loses some
 * findings 23 days before this boundary. Reading every channel's override would need a
 * `sales_channel:read` the insights viewer role does not grant, and would make the page 403 for
 * exactly the staff this module exists for.
 */
export function findingsPruned(run, findingCount, retentionDays, now) {
    if (findingCount > 0 || !run?.windowEnd) {
        return false;
    }

    return new Date(run.windowEnd).getTime() < retentionCutoff(retentionDays, now);
}

/**
 * `SearchOutcomes::MAX_TERMS`, mirrored.
 *
 * A list at exactly this length is a sample, not the whole truth, and the page has to say so — a
 * merchant who reads 25 words as "these are the 25 things nobody found" will stock 25 things and
 * still have the gap. **The two must stay equal**, and a test asserts it against the PHP constant.
 */
export const MAX_TERMS = 25;

/**
 * The two stored term lists for one run, each with the state its rendering depends on.
 *
 * **`pruned` is exact here, unlike the findings case.** A pruned finding leaves nothing behind, so
 * `findingsPruned()` above has to guess from the window's age. Retention treats these lists
 * differently: `InsightRetentionPruner` sets the whole `searchTerms` column to NULL, and the writer
 * never writes null — it always stores `{empty: [...], overCap: [...]}`, empty arrays included. So
 * null means "retention took the words" and an empty array means "there were none to take", and the
 * two states are distinguishable without reading a clock.
 *
 * `count` comes from `metrics`, not from the list's length, and that gap is the point: the counts
 * outlive the words (D23), so a pruned run still says how many turns found nothing while no longer
 * saying which.
 */
export function searchTermLists(run) {
    const stored = run?.searchTerms ?? null;
    const metrics = run?.metrics ?? {};

    // `metricKey` travels with the list so the heading can reuse the metric's own label rather than
    // carry a second copy of it. The chart legend and this heading then cannot drift apart, which
    // they would the first time one of the two was reworded.
    const list = (key, metricKey) => {
        const terms = stored?.[key] ?? [];

        return {
            key,
            metricKey,
            count: metrics[metricKey] ?? 0,
            // The denominator travels with the numerator so the template never divides and never
            // pairs a count with the wrong total. Zero when a run predates `searchTurns`, which the
            // page reads as "no denominator to show" rather than as a division by zero.
            total: metrics.searchTurns ?? 0,
            terms,
            pruned: stored === null,
            capped: terms.length >= MAX_TERMS,
        };
    };

    return [list('empty', 'turnsFoundNothing'), list('overCap', 'turnsOverCap')];
}

/**
 * The findings list's state for one selected run.
 *
 * Five, and no two of them mean the same thing to a merchant:
 *
 * - `loading` — a run was just picked and its findings are in flight. First, so the list cannot
 *   flash "nothing to report" for one frame on the way to four findings.
 * - `judgeFailed` — `judgeError` is set. The counts and the charts are intact and the judge did not
 *   answer; the run row carries the reason precisely so this is not read as a quiet night.
 * - `ready` — there are findings.
 * - `pruned` — no findings, and old enough that retention took them (D23: counts outlive quotes).
 * - `nothingFound` — no findings, inside the retention window. The only one of the five that is
 *   good news.
 */
export function runState({ run, findingCount, retentionDays, now, isLoading }) {
    if (isLoading) {
        return 'loading';
    }

    if (run?.judgeError) {
        return 'judgeFailed';
    }

    if (findingCount > 0) {
        return 'ready';
    }

    return findingsPruned(run, findingCount, retentionDays, now) ? 'pruned' : 'nothingFound';
}
