/**
 * Turns a flat list of trace events into something a merchant can read.
 *
 * The raw trace is ~18 rows per turn and treats bookkeeping and signal as equals: a live run of
 * "what trail jerseys do you have?" records `facet.probe`, `vocabulary.render` and `guard.check`
 * beside the two facts that matter — what was searched for, and what was shown. Read top to bottom
 * it answers no question a merchant actually has.
 *
 * Two things this derives that the row list cannot show:
 *
 * 1. **Phases.** Consecutive events belonging to the same part of the pipeline collapse into one
 *    line with one duration. Stage names stay available underneath; they stop being the structure.
 * 2. **The gaps.** Nothing is recorded while the model is thinking, so the model's latency is
 *    invisible in a list of events — it is the *space between* them. On that same live run the
 *    shop did 48ms of work and the two model round trips took 8.07s. Naming the gaps is the single
 *    most useful thing this page does, and it is only visible once events are grouped.
 */

const PHASES = [
    { key: 'prepare', stages: ['facet.probe', 'vocabulary.render', 'guard.check'] },
    { key: 'understand', stages: ['tool.call', 'understand', 'query.build', 'tool.arguments.rejected'] },
    { key: 'search', stages: ['retrieve', 'retrieve.narrow', 'retrieve.without_options', 'retrieve.relaxTerm', 'variant.resolve', 'blocklist.filter'] },
    /*
     * The one phase where something changed.
     *
     * `cart.add` used to be a second `tool.call` and therefore landed in `understand` — so the only
     * action in this product that alters the shop's state was filed under working out the question.
     * A merchant scanning for "did it actually put something in a cart" had to read the payloads to
     * find out.
     */
    { key: 'act', stages: ['cart.add'] },
    { key: 'answer', stages: ['validate', 'grounding.select', 'render', 'claims.audit'] },
    { key: 'finish', stages: ['turn.end', 'turn.tool_limit_exceeded', 'escalate'] },
];

const TERMINAL = ['turn.end', 'turn.tool_limit_exceeded'];

/**
 * Stages that run wherever they are needed rather than at a point in the pipeline.
 *
 * `facet.probe` fires once to warm the cache and again inside the search; `vocabulary.render` is
 * prompt bookkeeping. Letting either start a phase cut "Understood the question" in half on a real
 * turn and invented a second "Prepared" between the pieces — a phase that never happened. They
 * join whatever phase is already running and only form their own when nothing else has begun.
 */
const QUIET = ['facet.probe', 'vocabulary.render'];

/**
 * Below this a gap is scheduling noise, not a round trip. Model calls run in seconds; the largest
 * within-phase gap measured on a real turn was 17ms.
 */
const WAIT_THRESHOLD_MS = 250;

export function phaseOf(stage) {
    return PHASES.find((phase) => phase.stages.includes(stage))?.key ?? 'other';
}

/**
 * Splits events into turns. `seq` is monotonic across a whole conversation, but `elapsedMs`
 * restarts at each turn — so a drop in elapsed marks a boundary even when a `turn.end` is missing,
 * which happens when a turn died on an unhandled error.
 */
export function splitTurns(events) {
    const turns = [];
    let current = [];

    events.forEach((event, index) => {
        const previous = events[index - 1];

        if (current.length && previous && event.elapsedMs < previous.elapsedMs) {
            turns.push(current);
            current = [];
        }

        current.push(event);

        if (TERMINAL.includes(event.stage)) {
            turns.push(current);
            current = [];
        }
    });

    if (current.length) {
        turns.push(current);
    }

    return turns;
}

/**
 * One turn's events as an ordered list of phase groups, with `wait` entries standing in for the
 * gaps between them.
 */
export function buildTimeline(events) {
    const rows = [];
    let group = null;

    events.forEach((event) => {
        const key = QUIET.includes(event.stage) && group ? group.key : phaseOf(event.stage);

        if (group && group.key === key) {
            group.events.push(event);
            group.endMs = event.elapsedMs;

            return;
        }

        if (group) {
            const gap = event.elapsedMs - group.endMs;

            if (gap >= WAIT_THRESHOLD_MS) {
                rows.push({ type: 'wait', startMs: group.endMs, durationMs: gap });
            }
        }

        group = { type: 'phase', key, startMs: event.elapsedMs, endMs: event.elapsedMs, events: [event] };
        rows.push(group);
    });

    return closeSpans(rows);
}

/**
 * Extends each phase to where the next row begins, so the milliseconds add up.
 *
 * A gap under {@link WAIT_THRESHOLD_MS} belongs to the phase before it — nothing else was running.
 * Leaving those unattributed made `shopMs() + waitMs()` fall 18ms short of the turn on the live
 * fixture, which is the kind of quiet arithmetic hole that makes a diagnostic page untrustworthy.
 * A wait row already starts at the previous phase's last event, so this is a no-op there.
 */
function closeSpans(rows) {
    rows.forEach((row, index) => {
        const next = rows[index + 1];

        if (row.type === 'phase' && next) {
            row.endMs = next.startMs;
        }
    });

    return rows;
}

/** Milliseconds spent inside the shop, i.e. everything not sitting in a gap. */
export function shopMs(rows) {
    return rows
        .filter((row) => row.type === 'phase')
        .reduce((total, row) => total + (row.endMs - row.startMs), 0);
}

/** Milliseconds spent waiting on the model. */
export function waitMs(rows) {
    return rows.filter((row) => row.type === 'wait').reduce((total, row) => total + row.durationMs, 0);
}
