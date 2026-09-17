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
