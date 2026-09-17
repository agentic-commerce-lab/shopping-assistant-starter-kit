import { MAX_TERMS } from './runs';

/**
 * Every sentence this page composes from a snippet and a number.
 *
 * Split out of the page component when the denominator and the judge's discard notice pushed it
 * past the project's 400-line norm, and the seam is a real one: these are the strings where the
 * wording carries a claim, so the reasoning for each belongs together rather than scattered
 * through a component that is otherwise requests and markup.
 *
 * Each takes the translate function rather than reaching for a component instance, which keeps them
 * callable without mounting anything. **`$t`, never `$tc`, wherever a value is interpolated**:
 * `$tc`'s second argument is the pluralization choice and named values passed through it are
 * dropped silently — the trace list shipped a label reading "Export all 175 as" with nothing after
 * the "as" for exactly this reason.
 */

/** The window the run covered, as one sentence. */
export function windowLabel(t, run) {
    return t('swag-assistant-insights.lastNight.window', {
        start: Shopware.Utils.format.date(run.windowStart),
        end: Shopware.Utils.format.date(run.windowEnd),
    });
}

/**
 * One run, as one date, for the picker.
 *
 * **Not `windowLabel()`, and that is a defect found by looking at it.** The full window reads
 * "Covering 15 September 2026 at 09:00 to 16 September 2026 at 09:00" — 62 characters — and
 * `sw-single-select` does not clip its selected label: it wrapped out of the control, under the
 * chevron, in the first browser it was opened in. Widening the select was the wrong fix; the range
 * is redundant in a list where every row is one night, and the card's subtitle already prints the
 * window in full once a run is selected.
 *
 * The END of the window, not the start: a night's report is the one a merchant reads that morning,
 * and "16 September" is how they refer to it. The time stays because a replay can write a second
 * run ending on the same day, and two identical options in a dropdown is a choice nobody can make.
 *
 * Takes no translate function — a formatted date carries no words of ours.
 */
export function runLabel(run) {
    return Shopware.Utils.format.date(run.windowEnd, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
    });
}

/**
 * A term list's count as a proportion of the turns that actually searched.
 *
 * "9 turns over the match cap" is not a fact anybody can act on until they know whether there were
 * 14 turns or 200. The conversation count is the wrong denominator — one conversation holds several
 * turns and some search nothing at all — so `searchTurns` is the one that travels with these two.
 *
 * Falls back to the bare number when there is no denominator, which is any run written before
 * `searchTurns` existed. "9 of 0" would be worse than saying nothing at all.
 */
export function countLabel(t, list) {
    if (!list.total) {
        return String(list.count);
    }

    return t('swag-assistant-insights.terms.proportion', { count: list.count, total: list.total });
}

/**
 * That the judge answered and some of what it said was refused.
 *
 * **Never an error banner.** A handful of refused rows in a large batch is normal — a quote that
 * does not occur in the conversation it names, a type outside the closed set, a conversation
 * outside the sample — and a red box every night is how a reader learns to skip the one night it
 * matters. The loud part is the title the findings block adds when nothing at all survived, which
 * is the case where an empty worklist would otherwise read as a quiet night.
 */
export function discardedNotice(t, count) {
    return t('swag-assistant-insights.judge.discarded', { count });
}

/** That a term list is only the first {@see MAX_TERMS} of them. */
export function cappedNotice(t) {
    return t('swag-assistant-insights.terms.capped', { count: MAX_TERMS });
}

/**
 * Why an old run's findings list is empty, with the number that decided it.
 *
 * The retention window is named rather than described, because "older than your retention window"
 * invites the question this sentence exists to answer and the merchant would have to go and look it
 * up in another card.
 */
export function prunedNotice(t, days) {
    return t('swag-assistant-insights.empty.pruned', { days });
}
